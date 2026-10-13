<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessStudentBillPayment;
use App\Models\Organization;
use App\Models\StudentBillPayment;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hands consented bill payments to a worker, and recovers the ones nobody took.
 *
 * The after-commit wake is the normal path: it fires only once the payment that
 * produced the consent has actually landed, so a rolled-back consent never leaves
 * a transfer behind. The sweep exists for what a wake cannot cover — a broker
 * outage, a lane that was disabled when the consent was recorded, or a dispatch
 * that failed — and it creates the same durable work rather than a second kind of
 * it.
 *
 * The guarantee here is **one logical work item per payment**, not exactly-once
 * transport. A queue may deliver a job twice; that is safe because the job
 * re-reads the stored row and the write-ahead fence makes a second send
 * impossible. Claiming otherwise would be claiming the queue is exactly-once.
 */
final readonly class StudentBillPaymentDispatcher
{
    public function __construct(private InstallationInstitution $institutions) {}

    /**
     * Deliver one payment after the transaction that created it commits.
     */
    public function wakeAfterCommit(int $paymentId): void
    {
        if (! config('eduflow.student_bills.enabled', false)) {
            return;
        }

        DB::afterCommit(function () use ($paymentId): void {
            $this->deliver($paymentId);
        });
    }

    /**
     * Put one payment on a durable queue, reporting rather than throwing.
     *
     * Containment matters: the consent is already committed, and a payment that
     * cannot be dispatched must stay committed and visible rather than surface as
     * a failed student's bill.
     */
    public function deliver(int $paymentId): bool
    {
        if (! config('eduflow.student_bills.enabled', false) && ! config('eduflow.student_bills.observe_enabled', false)) {
            return false;
        }

        $connection = (string) config('eduflow.student_bills.queue_connection', 'database');

        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)
            || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= 180) {
            report(new \RuntimeException('Bill payment jobs need a durable queue with retry_after greater than the job timeout; nothing dispatched.'));

            return false;
        }

        try {
            Bus::dispatch(new ProcessStudentBillPayment($paymentId));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Redeliver committed payments no worker has taken.
     *
     * Recovery, not the first attempt to dispatch. Bounded per tick so one slow
     * sweep cannot try to drain the table, and it refuses anything whose consent
     * or identity no longer verifies rather than sending on a stale row.
     *
     * The sweep covers every state a worker could still advance, not only the
     * unsent ones. That distinction is the whole point: a payment the rail
     * accepted but which no worker ever observed again is a real transfer of
     * real money that the application then describes as incomplete. Reaching
     * {@see StudentBillPayment::SUBMISSION_VERIFIED} is safe because the
     * processor re-reads the row and only `submit()` can move money, and the
     * write-ahead fence refuses a second send.
     */
    public function dispatchDue(): int
    {
        $enabled = (bool) config('eduflow.student_bills.enabled', false);
        $maySubmit = $enabled && ! config('eduflow.student_bills.stop_switch', false);
        $mayObserve = (bool) config('eduflow.student_bills.observe_enabled', false);
        if (! $enabled && ! $mayObserve) {
            return 0;
        }

        $institution = $this->institutions->current();
        if (! $institution instanceof Organization) {
            return 0;
        }
        $count = 0;
        $states = [StudentBillPayment::SUBMISSION_VERIFIED];
        if ($maySubmit) {
            $states = [...$states, ...StudentBillPayment::SENDABLE_STATES];
        }
        if ($mayObserve) {
            $states = [...$states, ...StudentBillPayment::OBSERVABLE_STATES];
        }

        $payments = StudentBillPayment::query()
            ->where('organization_id', $institution->id)->where('simulated', false)
            ->whereIn('submission_state', $states)
            ->whereNull('allocated_at')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('leased_until')->orWhere('leased_until', '<=', now()))
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($payments as $payment) {
            if (! $payment->hasValidConsent()) {
                continue;
            }
            // The write-ahead fence sets `submitted_at` in the same claim that
            // leaves `prepared`. A row that somehow still says `prepared` with a
            // submit timestamp has nothing left to send, so delivering it would
            // inflate the recovered count with work the processor refuses.
            if (in_array($payment->submission_state, StudentBillPayment::SENDABLE_STATES, true)
                && $payment->submitted_at !== null) {
                continue;
            }
            $count += (int) $this->deliver((int) $payment->getKey());
        }

        return $count;
    }
}
