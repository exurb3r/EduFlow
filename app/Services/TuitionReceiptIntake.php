<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\ImportTuitionPaymentAsCollection;
use App\Jobs\ReconcileTuitionReceipt;
use App\Models\CollectionBatch;
use App\Models\StudentTuitionPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Unattended reconciliation of a settled portal payment into Collections.
 *
 * A staff member clicking "Reconcile student payment" is making a decision with
 * the receipt in front of them. This lane makes the same decision without one,
 * so it has to be strictly more conservative than the button, never less:
 *
 *  - **Off by default.** `eduflow.student_tuition.auto_intake` gates dispatch,
 *    and turning it on is a separate, reviewed operator decision.
 *  - **A verified attempt is required.** The historical `tx_verified_on_chain`
 *    flag alone is refused. That flag was written before immutable consent and
 *    write-once settlement evidence existed, and a receipt built on it could
 *    never be planned afterwards — automation must not manufacture a row the
 *    planner will reject.
 *  - **A named owner is the maker.** `prepared_by` records a real, verified
 *    finance account chosen by the operator, never the payer. Maker/checker
 *    separation still applies to it in full: the owner cannot review the
 *    reconciliation it caused.
 *  - **The identity is derived, not generated.** The capture key is a UUIDv5 of
 *    the movement key, so a replay reproduces the identical receipt instead of
 *    a second one, and no scheduler tick can invent a new identity per attempt.
 *
 * Reconciling still creates no authority: the receipt arrives unreviewed,
 * contributes nothing to planning cash, and authorizes no payment.
 */
final readonly class TuitionReceiptIntake
{
    public function __construct(private ImportTuitionPaymentAsCollection $import) {}

    /**
     * The deterministic identity one movement keeps, on every route.
     */
    public function captureKey(StudentTuitionPayment $payment): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'eduflow:tuition-intake:'.$payment->movement_key)->toString();
    }

    /**
     * Reconcile one payment, or explain why it must not be.
     *
     * @throws ValidationException when the movement is not strong enough to
     *                             enter the reviewed ledger unattended.
     */
    public function reconcile(StudentTuitionPayment $payment): CollectionBatch
    {
        if (! config('eduflow.student_tuition.auto_intake', false)) {
            throw ValidationException::withMessages(['intake' => 'Automatic tuition receipt intake is disabled; staff reconciliation remains available.']);
        }

        if (! $payment->isReal() || $payment->amount_base_units < 1 || ! is_string($payment->movement_key) || $payment->movement_key === '') {
            throw ValidationException::withMessages(['intake' => 'Only a posted, movement-keyed settlement may be reconciled automatically.']);
        }

        $attempt = $payment->attempt;
        if ($payment->student_tuition_payment_attempt_id === null || ! $attempt?->hasValidSettlement()) {
            throw ValidationException::withMessages(['intake' => 'Automated intake requires immutable consent and verified settlement proof on the attempt; a legacy verification flag is not enough.']);
        }

        return $this->import->handle($this->owner($payment), $payment, $this->captureKey($payment));
    }

    /**
     * The finance account an unattended reconciliation is attributed to.
     *
     * It must be a real, email-verified staff account, and it must not be the
     * student who paid: the maker of a receipt cannot be its reviewer, so the
     * payer must never inherit that role by accident.
     */
    private function owner(StudentTuitionPayment $payment): User
    {
        $owner = User::query()->find(config('eduflow.student_tuition.intake_owner_id'));
        if (! $owner instanceof User || ! $owner->hasVerifiedEmail()
            || ! $owner->hasAnyRole(['finance_officer', 'admin', 'super_admin'])) {
            throw ValidationException::withMessages(['intake' => 'Name a verified finance intake owner before automatic reconciliation is enabled.']);
        }

        if ($payment->student->user_id === $owner->id) {
            throw ValidationException::withMessages(['intake' => 'The intake owner cannot be the payer of a payment they reconcile.']);
        }

        return $owner;
    }

    /**
     * Payments that may be reconciled right now and have not been.
     *
     * @return Builder<StudentTuitionPayment>
     */
    public function pending(ImportTuitionPaymentAsCollection $import): Builder
    {
        return $import->eligiblePayments((string) config('lepton.arc.treasury'))
            ->whereNotNull('student_tuition_payment_attempt_id')
            ->whereNotNull('movement_key')
            ->whereHas('attempt', fn (Builder $attempt): Builder => $attempt->where('state', 'verified'))
            ->orderBy('id');
    }

    /**
     * Hand one committed settlement to a worker, after the commit that created it.
     *
     * A wake that cannot be delivered is reported and left recoverable: the
     * committed receipt is the durable fact, and the scheduler sweep redelivers
     * it. Rolling the posting transaction back discards the wake entirely, so a
     * rolled-back payment never leaves a receipt behind.
     */
    public function wakeAfterCommit(int $paymentId): void
    {
        if (! config('eduflow.student_tuition.auto_intake', false)) {
            return;
        }

        DB::afterCommit(function () use ($paymentId): void {
            $this->deliver($paymentId);
        });
    }

    /**
     * Redeliver committed settlements that no receipt covers yet.
     *
     * This is recovery, not the first attempt to import: the after-commit wake
     * is. Bounding the batch keeps one slow tick from sweeping the table.
     */
    public function dispatchDue(): int
    {
        $count = 0;
        foreach ($this->pending($this->import)->limit(50)->pluck('id') as $paymentId) {
            $count += (int) $this->deliver((int) $paymentId);
        }

        return $count;
    }

    /**
     * Put one payment on a durable queue, reporting rather than throwing.
     *
     * A failure here must not roll back the settlement that produced the
     * payment, so it is contained: the payment stays committed and unreviewed,
     * and the next sweep retries the same deterministic identity.
     */
    public function deliver(int $paymentId): bool
    {
        if (! config('eduflow.student_tuition.auto_intake', false)) {
            return false;
        }

        $connection = (string) config('eduflow.student_tuition.queue_connection', 'database');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)
            || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= 60) {
            report(new \RuntimeException('Tuition intake needs a durable queue with retry_after > 60 seconds; nothing was dispatched.'));

            return false;
        }

        try {
            Bus::dispatch(new ReconcileTuitionReceipt($paymentId));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
