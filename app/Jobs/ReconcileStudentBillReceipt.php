<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StudentBillPayment;
use App\Services\StudentBillReceiptIntake;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReconcileStudentBillReceipt implements ShouldQueue
{
    use Queueable;

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

    public function handle(StudentBillReceiptIntake $intake): void
    {
        $payment = StudentBillPayment::query()->find($this->paymentId);
        if ($payment === null || $payment->collections()->exists()) {
            return;
        }

        $intake->reconcile($payment);
    }

    public function failed(?Throwable $exception): void
    {
        report($exception ?? new \RuntimeException('Bill receipt intake exhausted retries; reconcile this movement from Collections.'));
    }
}
