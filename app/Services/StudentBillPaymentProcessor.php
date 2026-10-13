<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Student;
use App\Models\StudentBillPayment;
use App\Models\StudentWallet;
use App\Models\User;
use App\Services\Payments\AsyncPaymentTransport;
use App\Services\Payments\FeeBoundedPaymentTransport;
use App\Services\Payments\FeeGuarantee;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\AuthGateway;

/**
 * The only component permitted to ask a rail to move a student's money.
 *
 * It exists as a separate service so that everything above it — the quote, the
 * consent, the policy assessment — can be written and reviewed without any of them
 * holding a path to signing. It receives an already-consented payment and does
 * three things in order: re-check that consent is still intact, preflight, then
 * submit **once** behind a write-ahead fence. Everything after that observes.
 *
 * The state machine has exactly one sending edge and no way to add another:
 *
 *   prepared              -> submit()   (the only path that reaches a signer)
 *   submitting / accepted
 *   / awaiting_verification / unknown -> observe()   (resolves the EXISTING handle)
 *   verified (unallocated) -> allocate() via the existing proven proof path
 *   needs_operator_review  -> nothing    (a human decides)
 *
 * `submitted_at` is set inside the claim transaction *before* the external call,
 * and the model refuses to let a row that has it return to `prepared`. So a
 * timeout, a crash, a lost response or a retried job all converge on observing
 * one transfer rather than minting a second.
 */
final readonly class StudentBillPaymentProcessor
{
    public function __construct(
        private StudentBillPaymentQuote $payments,
        private InstallationInstitution $institutions,
        private AsyncPaymentTransport $transport,
        private ArcNetworkGateway $arc,
        private AuthGateway $auth,
        private StudentWalletService $balances,
    ) {}

    /**
     * Advance one payment as far as it can safely go on its own.
     */
    public function process(int $paymentId): void
    {
        $payment = StudentBillPayment::query()->findOrFail($paymentId);

        if ($payment->simulated) {
            // A rehearsal settles nothing, so there is nothing to observe and
            // nothing to allocate. Recording it as finished is the honest end.
            if ($payment->submission_state !== StudentBillPayment::SUBMISSION_VERIFIED) {
                $payment->update(['submission_state' => StudentBillPayment::SUBMISSION_VERIFIED,
                    'leased_until' => null, 'next_attempt_at' => null,
                    'last_error' => 'Rehearsal on a driver that cannot move money. Nothing was sent and nothing was allocated.']);
            }

            return;
        }

        if (! $payment->hasValidConsent()) {
            $this->hold($payment, 'Consent absent or no longer intact. Historical rows need independent investigation; never resend.');

            return;
        }

        if ($payment->allocated_at !== null) {
            return;
        }

        if ($payment->submission_state === StudentBillPayment::SUBMISSION_CONSENTED) {
            $this->prepare($payment);
        }

        $payment->refresh();

        if ($payment->submission_state === StudentBillPayment::SUBMISSION_PREPARED) {
            $this->submit($payment);

            return;
        }

        if ($payment->submission_state === StudentBillPayment::SUBMISSION_VERIFIED) {
            $this->allocate($payment);

            return;
        }

        if (in_array($payment->submission_state, [
            StudentBillPayment::SUBMISSION_SUBMITTING,
            StudentBillPayment::SUBMISSION_ACCEPTED,
            StudentBillPayment::SUBMISSION_AWAITING_VERIFICATION,
        ], true) && (bool) config('eduflow.student_bills.observe_enabled', false)) {
            $this->observe($payment);
        }
    }

    /**
     * Record that a payment is consented and could be dispatched.
     *
     * Split from {@see self::submit()} so that consent alone never implies a
     * signer: a payment only becomes dispatchable once an operator has enabled
     * the lane, and the transition is visible on the row before any transfer is
     * even possible.
     */
    private function prepare(StudentBillPayment $payment): void
    {
        if (! config('eduflow.student_bills.enabled', false) || config('eduflow.student_bills.stop_switch', false)) {
            $this->hold($payment, 'Bill submissions disabled or stopped. Consent retained; no signer called.');

            return;
        }

        $payment->update(['submission_state' => StudentBillPayment::SUBMISSION_PREPARED, 'next_attempt_at' => now()]);
    }

    /**
     * Preflight, then hand the transfer to the rail exactly once.
     */
    private function submit(StudentBillPayment $payment): void
    {
        if ($payment->submitted_at !== null) {
            return;
        }

        $guarantee = $this->feeGuarantee();

        try {
            $this->assertRail($payment);
            $lossBound = $this->assertAffordable($payment, $guarantee);
            $this->assertAuthority($payment);
        } catch (Throwable $exception) {
            report($exception);
            $this->hold($payment, 'Preflight blocked. '.$exception->getMessage());

            return;
        }

        // The write-ahead fence. Set inside the claim, before the external call,
        // so that any outcome at all — success, timeout, crash, or a process
        // killed mid-request — leaves a row that can only ever be observed.
        $claimed = DB::transaction(function () use ($payment, $lossBound): bool {
            $stored = StudentBillPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($stored->submitted_at !== null || $stored->submission_state !== StudentBillPayment::SUBMISSION_PREPARED
                || ! $stored->hasValidConsent()
                || ! config('eduflow.student_bills.enabled', false)
                || config('eduflow.student_bills.stop_switch', false)) {
                return false;
            }

            if (Carbon::parse($stored->snapshot['expires_at'])->lte(now())) {
                return false;
            }

            $wallet = StudentWallet::query()->findOrFail($stored->student_wallet_id);
            $student = Student::query()->findOrFail($stored->student_id);

            if ($wallet->status !== 'active' || strtolower($wallet->address) !== $stored->payer_address
                || strtolower((string) $student->payout_address) !== $stored->payer_address) {
                return false;
            }

            $stored->update([
                'submitted_at' => now(),
                'submission_state' => StudentBillPayment::SUBMISSION_SUBMITTING,
                'fee_guarantee' => $lossBound,
                'leased_until' => now()->addMinutes(5),
                'last_error' => null,
            ]);

            return true;
        }, 3);

        if (! $claimed) {
            return;
        }

        // External I/O is deliberately outside the retrying transaction.
        try {
            $acceptance = $this->transport->submit($this->request($payment));

            if (! Str::isUuid($acceptance->handle)) {
                throw ValidationException::withMessages(['provider' => 'Rail returned no usable transaction identifier; outcome unknown.']);
            }

            $payment->refresh()->update([
                'submission_state' => StudentBillPayment::SUBMISSION_ACCEPTED,
                'provider_handle' => strtolower($acceptance->handle),
                'leased_until' => null,
                'next_attempt_at' => now(),
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $payment->refresh()->update([
                'submission_state' => StudentBillPayment::SUBMISSION_SUBMITTING,
                'leased_until' => null,
                'next_attempt_at' => now()->addMinute(),
                'last_error' => 'Submission outcome unknown. Investigate this transfer; do not send it again.',
            ]);
        }
    }

    /**
     * Resolve the transfer that already exists.
     *
     * Never submits. A `pending` resolution is an ordinary state for an
     * asynchronous rail, so it reschedules rather than escalating; a lost handle
     * is the one case nothing can recover automatically, and it parks for a human.
     */
    private function observe(StudentBillPayment $payment): void
    {
        if ($payment->leased_until !== null && $payment->leased_until->isFuture()) {
            return;
        }

        $limit = max(1, min(100, (int) config('eduflow.student_bills.max_observations', 10)));
        if ($payment->observation_count >= $limit) {
            $this->hold($payment, 'Observation limit reached. A named operator must reconcile this existing transfer; never resend.');

            return;
        }

        $claimed = StudentBillPayment::query()->whereKey($payment->id)
            ->where(fn ($q) => $q->whereNull('leased_until')->orWhere('leased_until', '<=', now()))
            ->whereIn('submission_state', [
                StudentBillPayment::SUBMISSION_SUBMITTING,
                StudentBillPayment::SUBMISSION_ACCEPTED,
                StudentBillPayment::SUBMISSION_AWAITING_VERIFICATION,
            ])
            ->update(['leased_until' => now()->addMinutes(5), 'observation_count' => $payment->observation_count + 1]);

        if ($claimed !== 1) {
            return;
        }

        $payment->refresh();

        try {
            $this->assertRail($payment, observation: true);

            if ($payment->provider_reference === null) {
                if ($payment->provider_handle === null) {
                    $this->hold($payment, 'A signer may have been reached but no provider handle was captured. Reconcile this transfer manually; do not send again.');

                    return;
                }

                $resolution = $this->transport->resolve($payment->provider_handle, $this->request($payment));

                if (! $resolution->hasHash()) {
                    $payment->update([
                        'submission_state' => $resolution->state === 'failed'
                            ? StudentBillPayment::SUBMISSION_NEEDS_REVIEW
                            : StudentBillPayment::SUBMISSION_ACCEPTED,
                        'leased_until' => null,
                        'next_attempt_at' => $resolution->state === 'pending' ? now()->addMinute() : null,
                        'last_error' => $resolution->reason,
                    ]);

                    return;
                }

                if (preg_match('/^0x[0-9a-fA-F]{64}$/D', $resolution->txHash ?? '') !== 1) {
                    throw ValidationException::withMessages(['hash' => 'Rail reported completion with no usable chain hash.']);
                }

                $payment->update([
                    'provider_reference' => strtolower($resolution->txHash),
                    'submission_state' => StudentBillPayment::SUBMISSION_AWAITING_VERIFICATION,
                ]);
            }

            $proven = $this->payments->recordSettlement($payment->fresh(), (string) $payment->provider_reference);

            $proven->update([
                'submission_state' => StudentBillPayment::SUBMISSION_VERIFIED,
                'leased_until' => null,
                'next_attempt_at' => now(),
                'last_error' => null,
            ]);

            $this->allocate($proven);
        } catch (Throwable $exception) {
            report($exception);
            $payment->refresh()->update([
                'leased_until' => null,
                'next_attempt_at' => now()->addMinute(),
                'last_error' => mb_substr('Movement not yet proven, or the rail could not be read: '.$exception->getMessage(), 0, 1000),
            ]);
        }
    }

    /**
     * Apply proven movement to the bill. Delegates to the existing proven path so
     * there is exactly one implementation of "credit a bill".
     */
    private function allocate(StudentBillPayment $payment): void
    {
        try {
            $this->payments->allocate($payment);
        } catch (Throwable $exception) {
            report($exception);
            $payment->refresh()->update(['leased_until' => null, 'next_attempt_at' => now()->addMinute(),
                'last_error' => 'Verified transfer awaiting local allocation recovery. Do not resend.']);
        }
    }

    /**
     * What this rail can promise about network fees, or a refusal.
     *
     * A transport that does not implement the capability interface is read as
     * `Unbounded` rather than as "probably fine", because the whole point of asking
     * is to find out who pays before a signer is invoked.
     */
    private function feeGuarantee(): FeeGuarantee
    {
        return $this->transport instanceof FeeBoundedPaymentTransport
            ? $this->transport->feeGuarantee()
            : FeeGuarantee::Unbounded;
    }

    /**
     * Refuse unless the rail bounds what the payer can lose.
     *
     * On a sponsored rail the payer's whole debit is the principal, so the bar is
     * simply "can this wallet cover the amount". On an unbounded rail nothing
     * bounds the fee except the student's consent and there is no way to check what
     * it will be, so nothing is sent.
     */
    private function assertAffordable(StudentBillPayment $payment, FeeGuarantee $guarantee): string
    {
        if ($guarantee === FeeGuarantee::Unbounded) {
            throw ValidationException::withMessages(['fee' => 'This rail bounds network fees by no mechanism at all. No signer called.']);
        }

        $student = Student::query()->findOrFail($payment->student_id);
        $this->balances->forget($student);
        $balance = $this->balances->snapshot($student);

        if (! is_string($balance->balance) || $balance->isFake) {
            throw ValidationException::withMessages(['balance' => 'Fresh wallet balance unavailable; no signer called.']);
        }

        $available = BigDecimal::of($balance->balance)->withPointMovedRight(6)->toScale(0, RoundingMode::Floor)->toInt();
        $required = $guarantee === FeeGuarantee::RelayerSponsored
            ? $payment->amount_base_units
            : BigDecimal::of($payment->amount_base_units)->plus($payment->feeCeilingBaseUnits())->toInt();

        if ($available < $required) {
            throw ValidationException::withMessages(['balance' => 'Fresh wallet balance cannot cover what this rail will debit the payer for. No signer called.']);
        }

        return $guarantee->value;
    }

    /**
     * Two independent human decisions must stand behind any signer.
     *
     * The student's consent authorises *their* transfer. It says nothing about the
     * institution's ability to account for it, so a named finance owner — never the
     * payer — carries the second decision, and the operator session must already
     * exist. Nothing here logs in: the OTP needs a human.
     *
     * @throws ValidationException
     */
    private function assertAuthority(StudentBillPayment $payment): void
    {
        if (! in_array($payment->student_bill_id, config('eduflow.student_bills.pilot_bill_ids', []), true)) {
            throw ValidationException::withMessages(['pilot' => 'This bill is not explicitly designated for the testnet tuition pilot. No signer called.']);
        }

        $owner = User::query()->find(config('eduflow.student_bills.recovery_owner_id'));

        if (! $owner instanceof User || ! $owner->hasVerifiedEmail()
            || ! $owner->hasAnyRole(['finance_officer', 'admin', 'super_admin'])
            || $owner->id === $payment->quoted_for) {
            throw ValidationException::withMessages(['owner' => 'Name a verified finance recovery owner who is not the payer. No signer called.']);
        }

        if (! $this->auth->isAuthenticatedFor(StudentBillPayment::CHAIN)) {
            throw ValidationException::withMessages(['auth' => 'Operator testnet session unavailable. No automated login or signer call.']);
        }
    }

    /**
     * The rail's own agreement to be Arc testnet, with no fallback.
     *
     * Mainnet, a simulated driver, a chain-id disagreement or a different
     * institution is a refusal rather than a quiet rehearsal of what looked like a
     * real payment.
     *
     * @throws ValidationException
     */
    private function assertRail(StudentBillPayment $payment, bool $observation = false): void
    {
        if ($this->institutions->require()->id !== $payment->organization_id
            || $this->payments->isSimulated()
            || config('lepton.arc.chain') !== StudentBillPayment::CHAIN
            || config('lepton.arc.chain_id') !== StudentBillPayment::CHAIN_ID
            || $this->arc->chainCode() !== StudentBillPayment::CHAIN
            || $this->arc->chainId() !== StudentBillPayment::CHAIN_ID
            || strtolower((string) $this->arc->rpc('eth_chainId')) !== '0x4cef52'
            || $payment->chain !== StudentBillPayment::CHAIN) {
            throw ValidationException::withMessages(['rail' => 'Real Arc testnet (5042002) required for this installation; no mainnet or simulated fallback.']);
        }

        if (! $observation && $payment->recipient_address !== $this->payments->recipientAddress()) {
            throw ValidationException::withMessages(['rail' => 'The configured treasury changed since this quote was mounted. Obtain fresh consent.']);
        }
    }

    /**
     * @return array{from: string, to: string, amount_base_units: int, chain: string, idempotency_key: string}
     */
    private function request(StudentBillPayment $payment): array
    {
        return [
            'from' => $payment->payer_address,
            'to' => $payment->recipient_address,
            'amount_base_units' => $payment->amount_base_units,
            'chain' => $payment->chain,
            // The provider idempotency key is the same durable request identity
            // the consent is bound to, so a retry at the rail cannot become a
            // second transfer even if this application lost track of one.
            'idempotency_key' => $payment->request_key,
        ];
    }

    /**
     * Park a payment for a person, preserving every piece of evidence on it.
     */
    private function hold(StudentBillPayment $payment, string $reason): void
    {
        $payment->update([
            'submission_state' => StudentBillPayment::SUBMISSION_NEEDS_REVIEW,
            'leased_until' => null,
            'next_attempt_at' => null,
            'last_error' => mb_substr($reason, 0, 1000),
        ]);
    }
}
