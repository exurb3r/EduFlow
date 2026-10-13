<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StudentTuitionPaymentAttempt;
use App\Services\StudentTuitionPaymentProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessStudentTuitionPayment implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    public function __construct(public int $attemptId)
    {
        $this->onConnection((string) config('eduflow.student_tuition.queue_connection', 'database'));
        $this->onQueue((string) config('eduflow.student_tuition.queue', 'student-tuition'));
        $this->afterCommit();
    }

    public function handle(StudentTuitionPaymentProcessor $processor): void
    {
        $processor->process($this->attemptId);

        $attempt = StudentTuitionPaymentAttempt::query()->find($this->attemptId);
        if ($attempt !== null && in_array($attempt->state, ['accepted', 'awaiting_verification'], true)
            && $attempt->posted_at === null && $attempt->attempts < 10) {
            self::dispatch($this->attemptId)->delay(now()->addSeconds(5));
        }
    }

    public function failed(?Throwable $exception): void
    {
        report($exception ?? new \RuntimeException('Tuition job exhausted retries; inspect existing attempt without resending.'));
    }
}
