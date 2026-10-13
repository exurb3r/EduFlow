<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\StudentBillPaymentProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Advances one consented bill payment.
 *
 * The job holds no decision and no payload beyond an id: everything it acts on is
 * re-read from the stored row, so a redelivered or stale job acts on current
 * evidence rather than on a snapshot taken when it was queued. That is what makes
 * a duplicate delivery harmless — the write-ahead fence means the second run can
 * only ever observe the transfer the first one created.
 */
class ProcessStudentBillPayment implements ShouldQueue
{
    use Queueable;

    /** Below the queue's retry_after, so a slow run is never re-dispatched while alive. */
    public int $timeout = 120;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public int $paymentId)
    {
        $this->onConnection((string) config('eduflow.student_bills.queue_connection', 'database'));
        $this->onQueue((string) config('eduflow.student_bills.queue', 'student-bills'));
        $this->afterCommit();
    }

    public function handle(StudentBillPaymentProcessor $processor): void
    {
        $processor->process($this->paymentId);
    }

    public function failed(?Throwable $exception): void
    {
        report($exception ?? new \RuntimeException('Bill payment job exhausted its retries; reconcile this existing transfer rather than sending again.'));
    }
}
