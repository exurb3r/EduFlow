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
        if (! config('eduflow.student_bills.enabled', false)) {
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
     */
    public function dispatchDue(): int
    {
        if (! config('eduflow.student_bills.enabled', false)) {
            return 0;
        }

        $institution = $this->institutions->current();
        $count = 0;

        $payments = StudentBillPayment::query()
            ->where('submission_state', StudentBillPayment::SUBMISSION_PREPARED)
            ->whereNull('submitted_at')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($payments as $payment) {
            if (! $institution instanceof Organization) {
                continue;
            }
            if ($payment->organization_id !== $institution->id) {
                continue;
            }
            if (! $payment->hasValidConsent()) {
                continue;
            }
            $count += (int) $this->deliver((int) $payment->getKey());
        }

        return $count;
    }
}
