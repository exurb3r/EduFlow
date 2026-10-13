<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\CollectionBatch;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\StudentBillPayment;
use App\Models\StudentTuitionPayment;
use App\Models\User;
use App\Services\FinanceWorkflows;
use App\Services\InstallationInstitution;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reconciles a verified student payment into Collections as a receipt.
 *
 * A portal payment became cash when it settled, so this does not record a
 * receipt — it *reconciles* one that already exists. Three consequences shape
 * every line:
 *
 *  - **Only a proven movement is importable.** A rehearsal, an unverified hash
 *    or a payment addressed anywhere but the configured treasury is refused.
 *    Importing one would put a number into a staff-reviewed planning figure
 *    that no chain read supports.
 *  - **The link is unique.** One payment reconciles at most once, and the
 *    evidence fingerprint is derived from the movement, so a second attempt is
 *    a duplicate rather than a second receipt.
 *  - **The overpayment is restricted, not revenue.** The treasury received the
 *    whole amount, but the portion that exceeded the student's debt is owed
 *    back to them. Recording it as spendable cash is how a budget plans money
 *    it may have to return.
 *
 * The amounts are exact six-decimal USDC base units. No currency is implied
 * anywhere: the receipt is USDC, so it can only ever bind into a USDC planning
 * snapshot alongside other USDC, never beside local-currency collections.
 */
final readonly class ImportTuitionPaymentAsCollection
{
    public function __construct(private InstallationInstitution $institutions, private FinanceWorkflows $workflows) {}

    /**
     * Payments this installation may reconcile right now.
     *
     * "May" is deliberately narrow: real, already received, addressed to this
     * installation's treasury, and not reconciled before. An installation is
     * single-organization by construction, so the destination address is what
     * scopes a movement — a payment sent elsewhere is someone else's inflow and
     * must never appear in this installation's planning figures.
     *
     * @return Builder<StudentTuitionPayment>
     */
    public function eligiblePayments(string $treasuryAddress): Builder
    {
        return StudentTuitionPayment::query()
            ->where('simulated', false)
            ->where('tx_verified_on_chain', true)
            ->where('recipient_address', $treasuryAddress)
            ->whereDoesntHave('collections')
            ->orderByDesc('executed_at');
    }

    /** @return Builder<StudentBillPayment> */
    public function eligibleBillPayments(string $treasuryAddress): Builder
    {
        return StudentBillPayment::query()
            ->where('organization_id', $this->institutions->current()?->id)
            ->where('simulated', false)->where('tx_verified_on_chain', true)
            ->where('recipient_address', $treasuryAddress)->whereNotNull('allocated_at')
            ->where('chain', StudentBillPayment::CHAIN)->whereNotNull('executed_at')->whereNotNull('settlement_verified_at')
            ->whereNotNull('settlement_evidence_digest')->whereDoesntHave('collections')
            ->orderByDesc('executed_at');
    }

    /**
     * Reconcile one verified payment into an immutable receipt.
     *
     * @param  string  $captureKey  The identity this reconciliation keeps; a replay under the same key returns the same receipt.
     *
     * @throws ValidationException when the payment cannot honestly be reconciled
     */
    public function handle(User $actor, StudentTuitionPayment|StudentBillPayment $payment, string $captureKey): CollectionBatch
    {
        Gate::forUser($actor)->authorize('create', CollectionBatch::class);

        $institution = $this->institutions->require();
        $treasury = (string) config('lepton.arc.treasury');
        $currency = 'USDC';
        $isBill = $payment instanceof StudentBillPayment;
        $linkColumn = $isBill ? 'student_bill_payment_id' : 'student_tuition_payment_id';
        $this->assertReconcilable($payment, $treasury);
        if ($isBill && $payment->organization_id !== $institution->id) {
            throw ValidationException::withMessages(['payment' => 'Bill movement belongs to a different institution.']);
        }

        // Already exact six-decimal base units from the verified movement. No
        // conversion is involved anywhere on this path, which is the only way an
        // amount a chain read confirmed can stay identical on the way to a
        // staff-reviewed planning figure.
        $received = $payment->amount_base_units;
        $restricted = $payment->overpayment_base_units;

        // `collected_from` must precede `collected_until`, and a payment has a
        // single instant. One second earlier is the narrowest honest window that
        // does not claim the receipt covers time before it happened.
        $until = $payment->executed_at->copy()->utc();
        $from = $until->copy()->subSecond();

        $snapshot = [
            'schema_version' => 1,
            'purpose' => 'aggregate_realized_fee_receipts',
            'capture_key' => Str::lower($captureKey),
            'institution_id' => $institution->id,
            'prepared_by' => $actor->id,
            'source_stream' => $isBill ? CollectionBatch::BILL_PORTAL_STREAM : CollectionBatch::TUITION_PORTAL_STREAM,
            'source_reference' => strtolower((string) $payment->tx_hash),
            'source_document_digest' => CollectionBatch::movementDigest($payment),
            'currency' => $currency,
            'received_minor_units' => (string) $received,
            'restricted_minor_units' => (string) $restricted,
            'collected_from' => $from->toIso8601String(),
            'collected_until' => $until->toIso8601String(),
            'cash_evidence_reference' => 'Verified Arc movement '.$payment->tx_hash.' for student '.$payment->student_id
                .' on '.$payment->chain.'. The amount is already in the treasury balance; this receipt reconciles it, it does not record a new arrival.',
            // Machine-derived, not staff-typed: the fingerprint is a function of
            // the movement, so this receipt cannot overlap any other, and the
            // amount was settled rather than forecast.
            'source_stream_disjoint_attested' => true,
            'received_not_forecast_attested' => true,
            'evidence_origin' => 'verified_chain_movement',
            'movement' => [
                'request_key' => $payment->request_key,
                'tx_hash' => $payment->tx_hash,
                'amount_base_units' => $payment->amount_base_units,
                'applied_base_units' => $payment->applied_base_units,
                'overpayment_base_units' => $payment->overpayment_base_units,
                'payer_address' => $payment->payer_address,
                'recipient_address' => $payment->recipient_address,
                'settlement_verdict' => 'verified',
                'settlement_verified_at' => $until->toIso8601String(),
            ],
        ];

        if ($isBill) {
            $snapshot['movement']['student_bill_id'] = $payment->student_bill_id;
            $snapshot['movement']['chain'] = $payment->chain;
            $snapshot['movement']['settlement_evidence_digest'] = $payment->settlement_evidence_digest;
        }

        return DB::transaction(function () use ($actor, $institution, $payment, $treasury, $snapshot, $from, $until, $received, $restricted, $linkColumn): CollectionBatch {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('create', CollectionBatch::class);

            $stored = $payment->newQuery()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $this->assertReconcilable($stored, $treasury);
            if (! hash_equals(CollectionBatch::movementDigest($stored), $snapshot['source_document_digest'])
                || $stored->applied_base_units !== $payment->applied_base_units
                || $stored->overpayment_base_units !== $restricted) {
                throw ValidationException::withMessages(['payment' => 'Movement or allocation changed before reconciliation; reload exact evidence.']);
            }

            /** @var CollectionBatch|null $existing */
            $existing = CollectionBatch::query()->where('capture_key', $snapshot['capture_key'])->first();

            if ($existing !== null) {
                if (! $existing->hasValidSnapshot() || ! hash_equals($existing->snapshot_digest, PaymentIntent::digest($snapshot))) {
                    throw ValidationException::withMessages(['payment' => 'This reconciliation identity already binds a different movement or amount.']);
                }

                return $existing;
            }

            if (CollectionBatch::query()->where($linkColumn, $payment->getKey())->exists()) {
                throw ValidationException::withMessages(['payment' => 'This payment is already reconciled as a collection receipt.']);
            }

            $duplicate = CollectionBatch::query()->where('organization_id', $institution->id)
                ->where(fn (Builder $query): Builder => $query->where('source_document_digest', $snapshot['source_document_digest'])
                    ->orWhere(fn (Builder $movement): Builder => $movement->whereIn('source_stream', [CollectionBatch::TUITION_PORTAL_STREAM, CollectionBatch::BILL_PORTAL_STREAM])
                        ->where('source_reference', $snapshot['source_reference'])))
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages(['payment' => 'This movement is already reconciled as a collection receipt.']);
            }

            $batch = CollectionBatch::query()->create([
                'capture_key' => $snapshot['capture_key'],
                'organization_id' => $institution->id,
                'prepared_by' => $actor->id,
                $linkColumn => $payment->getKey(),
                'source_stream' => $snapshot['source_stream'],
                'source_reference' => $snapshot['source_reference'],
                'source_document_digest' => $snapshot['source_document_digest'],
                'currency' => $snapshot['currency'],
                'received_minor_units' => $received,
                'restricted_minor_units' => $restricted,
                'collected_from' => $from,
                'collected_until' => $until,
                'snapshot' => $snapshot,
                'snapshot_digest' => PaymentIntent::digest($snapshot),
            ]);

            $this->workflows->collectionCaptured($batch);

            activity('finance')
                ->causedBy($actor)
                ->performedOn($batch)
                ->event('collection_batch_reconciled_from_movement')
                ->withProperties([
                    'snapshot_digest' => $batch->snapshot_digest,
                    $linkColumn => $payment->getKey(),
                    'movement_tx_hash' => $payment->tx_hash,
                    'received_minor_units' => $received,
                    'restricted_overpayment_base_units' => $restricted,
                    'can_execute' => false,
                ])
                ->log('Verified student movement reconciled as an existing receipt; no new cash arrived and no payment authorized');

            return $batch;
        }, 3);
    }

    /**
     * Re-check under the lock that this movement can honestly be reconciled.
     *
     * The read that produced the payment is not re-done here: the chain already
     * settled it, and asking again would either change nothing or introduce a
     * second opinion. What is re-checked is everything that could have moved
     * since — the payment's own eligibility, and whether another receipt was
     * written for it in between.
     *
     * @throws ValidationException
     */
    private function assertReconcilable(StudentTuitionPayment|StudentBillPayment $payment, string $treasuryAddress): void
    {
        $proven = $payment instanceof StudentBillPayment
            ? $payment->hasValidSettlement() && $payment->isAllocated()
            : $payment->isReal();
        if (! $proven || $payment->executed_at === null) {
            throw ValidationException::withMessages(['payment' => 'Only a payment whose movement was verified on chain can be reconciled. A simulation settles nothing.']);
        }

        if ($payment instanceof StudentBillPayment
            && ($payment->settlement_verified_at === null || $payment->settlement_verified_at->isFuture()
                || $payment->executed_at->isFuture() || $payment->chain !== StudentBillPayment::CHAIN
                || $payment->applied_base_units < 0 || $payment->overpayment_base_units < 0
                || ! BigInteger::of($payment->applied_base_units)->plus($payment->overpayment_base_units)->isEqualTo($payment->amount_base_units))) {
            throw ValidationException::withMessages(['payment' => 'Bill receipts require exact verified Arc-testnet settlement and applied/overpayment allocation.']);
        }

        if (preg_match('/^0x[0-9a-fA-F]{64}$/D', (string) $payment->tx_hash) !== 1) {
            throw ValidationException::withMessages(['payment' => 'This payment has no verifiable transaction hash to reconcile against.']);
        }

        if ($payment->amount_base_units <= 0) {
            throw ValidationException::withMessages(['payment' => 'This payment moved no amount, so there is nothing to reconcile.']);
        }

        if ($payment->overpayment_base_units < 0 || $payment->overpayment_base_units > $payment->amount_base_units) {
            throw ValidationException::withMessages(['payment' => 'This payment records an impossible split between tuition applied and overpayment held.']);
        }

        if (strcasecmp((string) $payment->recipient_address, $treasuryAddress) !== 0) {
            throw ValidationException::withMessages(['payment' => 'This payment was not addressed to this institution\'s treasury, so it is not this institution\'s money.']);
        }
    }
}
