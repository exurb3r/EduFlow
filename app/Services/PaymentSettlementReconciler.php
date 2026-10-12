<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\PaymentIntent;
use App\Models\PaymentSubmissionAttempt;
use App\Models\PaymentSubmissionOutbox;
use App\Models\Transaction;
use App\Services\Payments\AsyncPaymentTransport;
use Illuminate\Support\Carbon;
use Throwable;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * Decides whether a submitted reference actually became money on Arc.
 *
 * This is the step that makes settlement a fact rather than an intention. A
 * provider reference is recorded by the executor; only `verified` from
 * `ArcSettlementVerifier` — successful execution in a committed block with
 * matching sender, recipient and amount — completes an entry.
 *
 * Every other verdict keeps capacity held and opens an investigation. In
 * particular `pending` is not a failure and `not_found` is not fabrication.
 */
final readonly class PaymentSettlementReconciler
{
    public function __construct(private ArcSettlementVerifier $verifier) {}

    /**
     * @return array<string, mixed>
     */
    public function reconcile(PaymentSubmissionOutbox $entry): array
    {
        // An accepted transfer has no on-chain reference yet, so it cannot be
        // verified. It can, however, be *observed*: asking the rail whether the
        // transaction it already owns has finished is the only legitimate next
        // step, and it never re-sends.
        if ($entry->state === 'accepted') {
            return $this->resolveAcceptance($entry);
        }

        if ($entry->state !== 'submitted' || ($entry->result['provider_reference'] ?? null) === null) {
            return ['verdict' => 'not_applicable', 'settled' => false,
                'reason' => 'Only a submitted entry with a provider reference can be verified.'];
        }

        $attempt = PaymentSubmissionAttempt::query()
            ->where('payment_submission_outbox_id', $entry->id)
            ->where('outcome', 'submitted')
            ->orderByDesc('attempt_number')
            ->first();

        $expected = [
            'chain' => $entry->snapshot['chain'] ?? null,
            'chain_id' => $entry->snapshot['chain_id'] ?? null,
            'sender' => $entry->snapshot['source_address'] ?? '',
            'recipient' => $entry->snapshot['recipient_address'] ?? '',
            'amount_base_units' => (int) ($entry->snapshot['amount_base_units'] ?? 0),
            'max_fee_base_units' => (int) ($entry->snapshot['max_fee_base_units'] ?? 0),
        ];

        try {
            $verdict = $this->verifier->verify($expected, (string) $entry->result['provider_reference']);
        } catch (Throwable $exception) {
            report($exception);

            $verdict = ['verdict' => 'unreadable', 'settled' => false, 'reason' => 'Verifier failed: '.$exception->getMessage()];
        }

        $entry->heartbeat_at = Carbon::now();
        $entry->last_error = $verdict['settled'] ? null : mb_substr((string) $verdict['reason'], 0, 1000);

        // The provider reference is the evidence this verdict is about, so it
        // is carried forward rather than replaced. A completed entry that no
        // longer names what it verified cannot be audited against the chain.
        $reference = $entry->result['provider_reference'] ?? null;

        if ($verdict['settled'] === true) {
            $entry->state = 'completed';
            $entry->stage = 'verified_settled';
        } else {
            // Still owed: capacity stays held and the case stays open.
            $entry->state = 'unknown';
            $entry->stage = 'unverified_'.($verdict['verdict'] ?? 'unknown');
        }

        $entry->result = array_filter([
            'provider_handle' => $entry->provider_handle,
            'provider_reference' => $reference,
        ], fn (mixed $value): bool => $value !== null)
            + $verdict
            + ['payments_submitted' => 1, 'can_execute' => false];
        $entry->result_digest = PaymentIntent::digest($entry->result);
        $entry->save();

        activity('finance')->performedOn($entry)->event('payment_submission_'.($verdict['settled'] ? 'verified' : 'unverified'))
            ->withProperties([
                'payment_intent_id' => $entry->payment_intent_id,
                'verdict' => $verdict['verdict'] ?? null,
                'attempt_number' => $attempt?->attempt_number,
                'settled' => $verdict['settled'] === true,
                'fabricated' => false,
            ])
            ->log((string) $verdict['reason']);

        return $verdict;
    }

    /**
     * Observe a transfer the rail has accepted but not yet published on chain.
     *
     * The rail's transaction id is resolved into a hash here and nowhere else.
     * Until one exists the entry stays `accepted` with capacity held: the money
     * may well have moved, and claiming otherwise would be exactly as false as
     * claiming it did.
     *
     * @return array<string, mixed>
     */
    private function resolveAcceptance(PaymentSubmissionOutbox $entry): array
    {
        $handle = $entry->provider_handle;

        if (! is_string($handle) || $handle === '') {
            return ['verdict' => 'unreadable', 'settled' => false,
                'reason' => 'An accepted entry carries no rail handle, so it cannot be observed. Investigate before any retry.'];
        }

        if ((string) config('eduflow.submission.transport', 'auto') === 'synchronous') {
            return ['verdict' => 'unreadable', 'settled' => false,
                'reason' => 'This entry was accepted by an asynchronous rail that is no longer configured. It cannot be observed or safely retried.'];
        }

        try {
            $resolution = app(AsyncPaymentTransport::class)->resolve($handle, [
                'from' => $entry->snapshot['source_address'],
                'chain' => $entry->snapshot['chain'],
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return ['verdict' => 'unreadable', 'settled' => false,
                'reason' => 'The rail could not be asked about this transfer. Outcome unknown; capacity stays held.'];
        }

        $entry->heartbeat_at = Carbon::now();

        if (! $resolution->hasHash()) {
            // Pending, failed and unknown all keep the entry out of submission
            // and keep capacity held. They differ only in what a human is told.
            $entry->state = $resolution->state === 'failed' ? 'failed' : $entry->state;
            $entry->stage = 'accepted_'.$resolution->state;
            $entry->last_error = mb_substr((string) $resolution->reason, 0, 1000);
            $entry->result = ['provider_handle' => $handle, 'provider_reference' => null,
                'verdict' => $resolution->state, 'settled' => false,
                'payments_submitted' => 1, 'can_execute' => false];
            $entry->result_digest = PaymentIntent::digest($entry->result);
            $entry->save();

            activity('finance')->performedOn($entry)->event('payment_submission_observed')
                ->withProperties(['payment_intent_id' => $entry->payment_intent_id,
                    'provider_handle' => $handle, 'rail_state' => $resolution->state, 'settled' => false])
                ->log((string) $resolution->reason);

            return ['verdict' => $resolution->state, 'settled' => false, 'reason' => $resolution->reason];
        }

        // A hash now exists, so this becomes an ordinary verification. The
        // handle is kept: it is how this transfer can be traced back to what the
        // rail was asked to do.
        $entry->state = 'submitted';
        $entry->stage = 'hash_resolved';
        $entry->result = ['provider_handle' => $handle, 'provider_reference' => $resolution->txHash,
            'verdict' => 'submitted', 'settled' => false, 'payments_submitted' => 1, 'can_execute' => false];
        $entry->result_digest = PaymentIntent::digest($entry->result);
        $entry->save();

        return $this->reconcile($entry->fresh());
    }

    /**
     * Mirror a verified settlement into the local transaction ledger.
     *
     * This is the only path in the codebase permitted to write a transaction
     * row for a vendor payment, and it writes one *only* for a verified Arc
     * settlement.
     *
     * Two honesty constraints shape the row. The exact amount is kept in
     * `metadata.amount_base_units` as well as the column, so the ledger can be
     * audited against the chain even if the column is ever narrowed again. And
     * a mirror never settles the institution's own payable: it reports that
     * money moved on Arc testnet, it is not a local accounting posting.
     *
     * @param  array<string, mixed>  $verdict
     */
    public function mirrorSettlement(PaymentSubmissionOutbox $entry, array $verdict): ?Transaction
    {
        if (($verdict['settled'] ?? false) !== true) {
            return null;
        }

        $baseUnits = (int) $entry->snapshot['amount_base_units'];

        /** @var PaymentIntent $intent */
        $intent = PaymentIntent::query()->findOrFail($entry->payment_intent_id);

        return Transaction::query()->create([
            'organization_id' => $entry->organization_id,
            'wallet_id' => $intent->wallet_id,
            'type' => TransactionType::VENDOR_PAYMENT->value,
            'recipient_address' => $entry->snapshot['recipient_address'],
            'amount' => Amounts::toDecimalString($baseUnits, 6),
            'currency' => $entry->snapshot['currency'] ?? 'USDC',
            'status' => TransactionStatus::CONFIRMED->value,
            'provider_tx_hash' => $entry->result['provider_reference'] ?? null,
            'network' => $entry->snapshot['chain'],
            'reference_type' => PaymentIntent::class,
            'reference_id' => $entry->payment_intent_id,
            'metadata' => [
                'settlement' => 'verified_arc_testnet_mirror',
                'amount_base_units' => (string) $baseUnits,
                'receipt' => $verdict['receipt'] ?? null,
                'actual_fee_base_units' => $verdict['actual_fee_base_units'] ?? null,
                'local_accounts_changed' => false,
                'settles_local_payable' => false,
            ],
            'executed_at' => Carbon::now(),
        ]);
    }
}
