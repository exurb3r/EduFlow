<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\StudentTuitionPaymentIntent;
use App\Models\StudentWallet;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Services\Payments\AsyncPaymentTransport;
use App\Services\Payments\FeeBoundedPaymentTransport;
use App\Services\Payments\FeeGuarantee;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\AuthGateway;

/** Only prepared consent may submit. Every other recovery path observes or posts, never resends. */
final readonly class StudentTuitionPaymentProcessor
{
    public function __construct(
        private StudentTuitionPaymentService $payments,
        private InstallationInstitution $institutions,
        private AsyncPaymentTransport $transport,
        private ArcNetworkGateway $arc,
        private AuthGateway $auth,
        private StudentWalletService $balances,
        private ArcSettlementVerifier $verifier,
        private TuitionReceiptIntake $intake,
    ) {}

    public function process(int $attemptId): void
    {
        $attempt = StudentTuitionPaymentAttempt::query()->findOrFail($attemptId);
        if (! $attempt->hasValidConsent()) {
            $this->hold($attempt, 'Consent absent or changed. Historical attempts require independent investigation; never resend.');

            return;
        }
        if ($attempt->state === 'prepared') {
            $this->submit($attempt);

            return;
        }
        if ($attempt->state === 'verified' && $attempt->posted_at === null) {
            try {
                $this->post($attempt);
            } catch (Throwable $exception) {
                report($exception);
                $this->hold($attempt, 'Verified transfer awaiting local posting recovery. No resend.');
            }

            return;
        }
        if (in_array($attempt->state, ['submitting', 'accepted', 'awaiting_verification', 'unknown'], true)
            && config('eduflow.student_tuition.observe_enabled', false)) {
            $this->observe($attempt);
        }
    }

    private function submit(StudentTuitionPaymentAttempt $attempt): void
    {
        if (! config('eduflow.student_tuition.enabled', false) || config('eduflow.student_tuition.stop_switch', false)) {
            $this->hold($attempt, 'New tuition submissions disabled or stopped. No signer called.');

            return;
        }
        try {
            $this->assertRail($attempt);
            $guarantee = $this->feeGuarantee();
            if ($guarantee === FeeGuarantee::Unbounded) {
                throw ValidationException::withMessages(['fee' => 'Rail bounds network fees by no mechanism at all. No signer called.']);
            }
            if (! $this->auth->isAuthenticatedFor('ARC-TESTNET')) {
                throw ValidationException::withMessages(['auth' => 'Operator testnet session unavailable. No automated login or signer call.']);
            }
            $intent = StudentTuitionPaymentIntent::query()->findOrFail($attempt->student_tuition_payment_intent_id);
            if (Carbon::parse($intent->snapshot['expires_at'])->lte(now())) {
                throw ValidationException::withMessages(['quote' => 'Consent quote expired before submission. No signer called.']);
            }
            $owner = User::query()->find($attempt->investigation_owner_id);
            if (! $owner instanceof User || ! $owner->hasVerifiedEmail()
                || ! $owner->hasAnyRole(['finance_officer', 'admin', 'super_admin']) || $owner->id === $intent->quoted_for) {
                throw ValidationException::withMessages(['owner' => 'Named verified finance recovery owner required before a signer can run.']);
            }
            $student = Student::query()->findOrFail($attempt->student_id);
            $account = TuitionAccount::query()->findOrFail($attempt->tuition_account_id);
            $wallet = $this->payments->payableWallet($student, $account, $attempt->amount_base_units);
            if ($wallet->id !== $attempt->student_wallet_id || strtolower($wallet->address) !== $attempt->payer_address) {
                throw ValidationException::withMessages(['wallet' => 'Payer wallet changed after consent.']);
            }
            $fee = StudentTuitionPaymentService::readFee($this->transport->estimate($this->request($attempt)));
            if ($guarantee !== FeeGuarantee::RelayerSponsored && StudentTuitionPaymentService::feeCeiling($fee) > $attempt->fee_ceiling_base_units) {
                throw ValidationException::withMessages(['fee' => 'Current network quote exceeds accepted fee ceiling.']);
            }
            $this->balances->forget($student);
            $balance = $this->balances->snapshot($student);
            if (! is_string($balance->balance) || $balance->isFake) {
                throw ValidationException::withMessages(['balance' => 'Fresh wallet balance unavailable; no signer called.']);
            }
            $lossBound = $this->assertLossBounded($attempt, $balance->balance, $guarantee);
        } catch (Throwable $exception) {
            report($exception);
            $intent = StudentTuitionPaymentIntent::query()->find($attempt->student_tuition_payment_intent_id);
            $isQuoteExpired = $intent instanceof StudentTuitionPaymentIntent
                && isset($intent->snapshot['expires_at'])
                && Carbon::parse($intent->snapshot['expires_at'])->lte(now());

            if ($attempt->submission_started_at === null && $isQuoteExpired) {
                $attempt->update([
                    'state' => 'failed',
                    'stage' => 'quote_expired_unsubmitted',
                    'last_error' => mb_substr('Consent quote expired before submission. No signer called.', 0, 1000),
                    'leased_until' => null,
                    'next_attempt_at' => null,
                ]);

                return;
            }

            $this->hold($attempt, 'Preflight blocked. '.$exception->getMessage());

            return;
        }

        $claimed = DB::transaction(function () use ($attempt, $lossBound): bool {
            $stored = StudentTuitionPaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($stored->state !== 'prepared' || $stored->submission_started_at !== null || ! $stored->hasValidConsent()
                || ! config('eduflow.student_tuition.enabled', false) || config('eduflow.student_tuition.stop_switch', false)) {
                return false;
            }
            $intent = StudentTuitionPaymentIntent::query()->findOrFail($stored->student_tuition_payment_intent_id);
            $account = TuitionAccount::query()->whereKey($stored->tuition_account_id)->lockForUpdate()->firstOrFail();
            $wallet = StudentWallet::query()->whereKey($stored->student_wallet_id)->firstOrFail();
            $student = Student::query()->whereKey($stored->student_id)->firstOrFail();
            if (Carbon::parse($intent->snapshot['expires_at'])->lte(now()) || $stored->amount_base_units > $account->remainingAmount()
                || $wallet->status !== 'active' || strtolower($wallet->address) !== $stored->payer_address
                || strtolower((string) $student->payout_address) !== $stored->payer_address) {
                if (Carbon::parse($intent->snapshot['expires_at'])->lte(now())) {
                    $stored->update([
                        'state' => 'failed',
                        'stage' => 'quote_expired_unsubmitted',
                        'last_error' => 'Consent quote expired before submission. No signer called.',
                        'leased_until' => null,
                        'next_attempt_at' => null,
                    ]);
                }

                return false;
            }
            $stored->update(['state' => 'submitting', 'stage' => 'signer_may_have_been_called', 'submission_started_at' => now(),
                'leased_until' => now()->addMinutes(5), 'fee_guarantee' => $lossBound, 'last_error' => null]);

            return true;
        }, 3);
        if (! $claimed) {
            return;
        }

        // External I/O is outside the retrying transaction. The write-ahead fence is never cleared.
        try {
            $accepted = $this->transport->submit($this->request($attempt));
            if (! Str::isUuid($accepted->handle)) {
                throw ValidationException::withMessages(['provider' => 'Malformed provider acceptance; outcome unknown.']);
            }
            $attempt->refresh()->update(['state' => 'accepted', 'stage' => 'awaiting_provider_hash', 'provider_handle' => strtolower($accepted->handle),
                'provider_accepted_at' => now(), 'leased_until' => null, 'next_attempt_at' => now(), 'last_error' => null]);
        } catch (Throwable $exception) {
            report($exception);
            $attempt->refresh()->update(['state' => 'unknown', 'stage' => 'investigate_existing_submission', 'last_error' => 'Submission outcome unknown. Do not send again.',
                'leased_until' => null, 'next_attempt_at' => now()->addMinute()]);
        }
    }

    private function observe(StudentTuitionPaymentAttempt $attempt): void
    {
        // Re-read the rail's own answer rather than the one captured at submit
        // time: a payment observed long after submission must be judged against
        // what the rail says now, not against a stale claim about it.
        $guarantee = $this->feeGuarantee();
        if ($attempt->leased_until !== null && $attempt->leased_until->isFuture()) {
            return;
        }
        if ($attempt->attempts >= max(1, min(100, (int) config('eduflow.student_tuition.max_observations', 10)))) {
            $this->hold($attempt, 'Observation limit reached. Assigned operator must reconcile existing submission; never resend.');

            return;
        }
        $claimed = StudentTuitionPaymentAttempt::query()->whereKey($attempt->id)
            ->where(fn ($q) => $q->whereNull('leased_until')->orWhere('leased_until', '<=', now()))
            ->whereIn('state', ['submitting', 'accepted', 'awaiting_verification', 'unknown'])
            ->update(['leased_until' => now()->addMinutes(5), 'attempts' => $attempt->attempts + 1]);
        if ($claimed !== 1) {
            return;
        }
        $attempt->refresh();
        try {
            $this->assertRail($attempt, observation: true);
            if ($attempt->provider_reference === null) {
                if ($attempt->provider_handle === null) {
                    $attempt->update(['state' => 'unknown']);
                    $this->hold($attempt, 'Submission may have occurred but response/handle was lost. Provider investigation required; no automatic resend.');

                    return;
                }
                $resolution = $this->transport->resolve($attempt->provider_handle, $this->request($attempt));
                if (! $resolution->hasHash()) {
                    $attempt->update(['state' => $resolution->state === 'pending' ? 'accepted' : 'unknown',
                        'stage' => $resolution->state === 'failed' ? 'provider_failure_requires_investigation' : 'awaiting_provider_hash',
                        'last_error' => $resolution->reason, 'leased_until' => null, 'next_attempt_at' => now()->addMinute()]);

                    return;
                }
                if (preg_match('/^0x[0-9a-fA-F]{64}$/D', $resolution->txHash ?? '') !== 1) {
                    throw ValidationException::withMessages(['hash' => 'Provider completion has no usable chain hash.']);
                }
                $attempt->update(['provider_reference' => strtolower($resolution->txHash), 'state' => 'awaiting_verification']);
            }
            $verdict = $this->verifier->verify(['chain' => $attempt->chain, 'chain_id' => $attempt->chain_id,
                'sender' => $attempt->payer_address, 'recipient' => $attempt->recipient_address,
                'amount_base_units' => $attempt->amount_base_units, 'max_fee_base_units' => $attempt->fee_ceiling_base_units], $attempt->provider_reference);
            $receipt = $verdict['receipt'] ?? null;
            $fee = is_array($receipt) ? $this->actualFee($receipt) : null;
            // Whether the receipt's fee may refuse the settlement depends on who
            // paid it, which depends on the rail -- see assertLossBounded(). On a
            // sponsored rail this fee is Circle's cost and says nothing about what
            // the student was debited, so letting it reject the settlement would
            // discard a real payment the student actually paid for.
            $feeMayRefuse = $guarantee !== FeeGuarantee::RelayerSponsored;
            if (($verdict['verdict'] ?? null) !== 'verified' || $fee === null
                || ($feeMayRefuse && StudentTuitionPaymentService::feeCeiling($fee) > $attempt->fee_ceiling_base_units)
                || strtolower((string) ($receipt['transactionHash'] ?? '')) !== $attempt->provider_reference) {
                $attempt->update(['settlement_verdict' => ($verdict['verdict'] ?? null) === 'verified' ? 'fee_or_reference_unproven' : (string) ($verdict['verdict'] ?? 'unreadable'),
                    'settlement_reason' => mb_substr((string) ($verdict['reason'] ?? 'Movement proof unavailable.'), 0, 1000),
                    'stage' => 'movement_or_fee_unproven', 'leased_until' => null, 'next_attempt_at' => now()->addMinute()]);

                return;
            }
            $proof = [...$verdict, 'consent_digest' => $attempt->consent_digest, 'tx_hash' => $attempt->provider_reference,
                'chain_id' => $attempt->chain_id, 'sender' => $attempt->payer_address, 'recipient' => $attempt->recipient_address,
                'amount_base_units' => $attempt->amount_base_units, 'actual_network_fee' => $fee, 'fee_paid_by' => $guarantee->value];
            $attempt->update(['state' => 'verified', 'stage' => 'movement_verified_posting_due', 'settlement_verdict' => 'verified',
                'settlement_reason' => mb_substr((string) $verdict['reason'], 0, 1000), 'settlement_verified_at' => now(),
                'settlement_evidence' => $proof, 'settlement_evidence_digest' => PaymentIntent::digest($proof), 'actual_network_fee' => $fee,
                'leased_until' => null, 'next_attempt_at' => now(), 'last_error' => null]);
            $this->post($attempt);
        } catch (Throwable $exception) {
            report($exception);
            $this->hold($attempt->refresh(), 'Observation or posting unavailable. Existing transfer retained; no resend.');
        }
    }

    public function post(StudentTuitionPaymentAttempt $attempt): void
    {
        if (! $attempt->hasValidSettlement() || $attempt->state !== 'verified') {
            throw ValidationException::withMessages(['settlement' => 'Intact consent and exact verified movement proof required for posting.']);
        }
        $paymentId = DB::transaction(function () use ($attempt): ?int {
            $intent = StudentTuitionPaymentIntent::query()->findOrFail($attempt->student_tuition_payment_intent_id);
            Organization::query()->whereKey($intent->organization_id)->lockForUpdate()->firstOrFail();
            $account = TuitionAccount::query()->whereKey($attempt->tuition_account_id)->lockForUpdate()->firstOrFail();
            $stored = StudentTuitionPaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($stored->posted_at !== null) {
                return null;
            }
            if (! $stored->hasValidSettlement()) {
                throw ValidationException::withMessages(['settlement' => 'Movement proof changed before posting.']);
            }
            $movementKey = PaymentIntent::digest(['chain' => $stored->chain_id, 'hash' => $stored->provider_reference,
                'sender' => $stored->payer_address, 'recipient' => $stored->recipient_address, 'amount' => $stored->amount_base_units]);
            if (StudentTuitionPayment::query()->where('movement_key', $movementKey)->orWhere('request_key', $stored->request_key)->exists()) {
                throw ValidationException::withMessages(['movement' => 'Movement already posted under an identity. Investigate without altering history.']);
            }
            $applied = min($stored->amount_base_units, max(0, $account->remainingAmount()));
            $payment = StudentTuitionPayment::query()->create(['student_tuition_payment_attempt_id' => $stored->id, 'movement_key' => $movementKey,
                'request_key' => $stored->request_key, 'student_id' => $stored->student_id, 'tuition_account_id' => $stored->tuition_account_id,
                'student_wallet_id' => $stored->student_wallet_id, 'amount_base_units' => $stored->amount_base_units,
                'applied_base_units' => $applied, 'overpayment_base_units' => $stored->amount_base_units - $applied,
                'network_fee' => $stored->actual_network_fee, 'payer_address' => $stored->payer_address,
                'recipient_address' => $stored->recipient_address, 'chain' => $stored->chain, 'tx_hash' => $stored->provider_reference,
                'tx_verified_on_chain' => true, 'simulated' => false, 'executed_at' => $stored->settlement_verified_at]);
            if ($applied > 0) {
                $account->update(['paid_amount' => BigInteger::of($account->paid_amount)->plus($applied)->toInt()]);
            }
            $stored->update(['posted_at' => now(), 'stage' => 'pilot_allocation_posted', 'next_attempt_at' => null]);
            // Pilot receipt is not official revenue and never credits the legacy float treasury ledger.
            // Intake is woken only once this commit lands, and only when an operator enabled it.
            $this->intake->wakeAfterCommit((int) $payment->getKey());

            return (int) $payment->getKey();
        }, 3);

        if ($paymentId === null) {
            return;
        }

        $this->reconcileNow($paymentId);
    }

    /**
     * Reconcile a payment this process just posted, without waiting for a worker.
     *
     * The after-commit wake is what makes the work durable, so it has already
     * been registered. Running it inline as well keeps the receipt available to
     * staff promptly instead of up to a queue round-trip later, and it is the
     * same idempotent call either way: the capture key is derived from the
     * movement, so whichever route wins, the loser replays it.
     *
     * A refusal is contained. Posting has already committed, so a missing flag,
     * an unnamed owner or a broker outage must not surface as a failed payment.
     */
    private function reconcileNow(int $paymentId): void
    {
        try {
            $payment = StudentTuitionPayment::query()->find($paymentId);
            if ($payment !== null && ! $payment->collections()->exists()) {
                $this->intake->reconcile($payment);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * What this rail can promise about network fees, or a refusal.
     *
     * A transport that does not implement the capability interface at all is
     * treated as `Unbounded`, not as "probably fine": an unknown rail must be
     * the most conservative reading, because the whole point of asking is to
     * find out who pays the fee before a signer is invoked.
     */
    private function feeGuarantee(): FeeGuarantee
    {
        return $this->transport instanceof FeeBoundedPaymentTransport
            ? $this->transport->feeGuarantee()
            : FeeGuarantee::Unbounded;
    }

    /**
     * Establish what the payer can actually lose, from the rail's own claim.
     *
     * Each guarantee implies a different affordability question, so this is
     * where the rail's answer is turned into an arithmetic check:
     *
     *  - `RelayerSponsored`: the rail pays the gas, so the payer's whole debit
     *    is the principal. Affordability is `amount <= balance` and a wallet
     *    holding exactly the amount may send it. This was measured, not assumed:
     *    the payer's balance moved by exactly the principal on three real
     *    relayed ARC-TESTNET transfers while the receipts still carried fees.
     *  - `SignerEnforced`: the transaction carries the ceiling, so the payer may
     *    spend up to principal plus the accepted ceiling.
     *  - `Unbounded`: refused before the signer, because nothing bounds the fee
     *    except consent and there is no way to check what it will be.
     *
     * The returned string is recorded on the attempt. "The rail paid the fee" and
     * "the transaction capped the fee" are different promises about a student's
     * money, and a later reader has to be able to tell which one was relied on.
     *
     * @throws ValidationException
     */
    private function assertLossBounded(StudentTuitionPaymentAttempt $attempt, string $balance, FeeGuarantee $guarantee): string
    {
        $available = BigDecimal::of($balance)->withPointMovedRight(6)->toScale(0, RoundingMode::Floor)->toInt();
        $required = $guarantee === FeeGuarantee::RelayerSponsored
            ? $attempt->amount_base_units
            : $attempt->total_base_units;

        if ($available < $required) {
            throw ValidationException::withMessages([
                'balance' => 'Fresh wallet balance cannot cover the amount this rail will debit the payer for. No signer called.',
            ]);
        }

        return $guarantee->value;
    }

    private function assertRail(StudentTuitionPaymentAttempt $attempt, bool $observation = false): void
    {
        $intent = StudentTuitionPaymentIntent::query()->findOrFail($attempt->student_tuition_payment_intent_id);
        if ($this->institutions->require()->id !== $intent->organization_id || $this->payments->isSimulated()
            || config('lepton.arc.chain') !== 'ARC-TESTNET' || config('lepton.arc.chain_id') !== 5042002
            || $this->arc->chainCode() !== 'ARC-TESTNET' || $this->arc->chainId() !== 5042002
            || strtolower((string) $this->arc->rpc('eth_chainId')) !== '0x4cef52'
            || $attempt->chain !== 'ARC-TESTNET' || $attempt->chain_id !== 5042002
            || (! $observation && $attempt->recipient_address !== $this->payments->recipientAddress())) {
            throw ValidationException::withMessages(['rail' => 'Original institution and real Arc testnet rail required; no mainnet or fake fallback.']);
        }
    }

    /** @return array{from:string,to:string,amount_base_units:int,chain:string,idempotency_key:string,max_fee_base_units?:int} */
    private function request(StudentTuitionPaymentAttempt $a): array
    {
        $request = [
            'from' => $a->payer_address,
            'to' => $a->recipient_address,
            'amount_base_units' => $a->amount_base_units,
            'chain' => $a->chain,
            'idempotency_key' => $a->request_key,
        ];

        if ($this->transport instanceof FeeBoundedPaymentTransport && $this->transport->supportsFeeCeiling()) {
            $request['max_fee_base_units'] = $a->fee_ceiling_base_units;
        }

        return $request;
    }

    /** @param array<string, mixed> $receipt */
    private function actualFee(array $receipt): ?string
    {
        foreach (['gasUsed', 'effectiveGasPrice'] as $field) {
            if (! is_string($receipt[$field] ?? null) || preg_match('/^0x[0-9a-fA-F]+$/D', $receipt[$field]) !== 1) {
                return null;
            }
        }

        return BigInteger::fromBase(substr($receipt['gasUsed'], 2), 16)->multipliedBy(BigInteger::fromBase(substr($receipt['effectiveGasPrice'], 2), 16))
            ->toBigDecimal()->withPointMovedLeft(18)->__toString();
    }

    private function hold(StudentTuitionPaymentAttempt $attempt, string $reason): void
    {
        $attempt->update(['stage' => 'needs_operator_review', 'last_error' => mb_substr($reason, 0, 1000), 'leased_until' => null, 'next_attempt_at' => null]);
    }
}
