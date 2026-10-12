<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessStudentTuitionPayment;
use App\Models\StudentTuitionPaymentAttempt;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class StudentTuitionPaymentDispatcher
{
    public function wakeAfterCommit(int $id): void
    {
        DB::afterCommit(function () use ($id): void {
            try {
                $this->dispatchOne($id);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function dispatchOne(int $id): bool
    {
        $a = StudentTuitionPaymentAttempt::query()->findOrFail($id);
        $submit = $a->state === 'prepared' && $a->submission_started_at === null;
        $observe = in_array($a->state, ['submitting', 'accepted', 'unknown', 'awaiting_verification', 'verified'], true) && $a->posted_at === null;
        if (! $a->hasValidConsent() || ($submit && ! config('eduflow.student_tuition.enabled', false))
            || ($observe && ! config('eduflow.student_tuition.observe_enabled', false)) || (! $submit && ! $observe)
            || $a->stage === 'needs_operator_review') {
            return false;
        }
        $connection = (string) config('eduflow.student_tuition.queue_connection', 'database');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)
            || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= 180) {
            throw ValidationException::withMessages(['queue' => 'Tuition jobs require durable queue with retry_after > 180 seconds.']);
        }
        $claimed = StudentTuitionPaymentAttempt::query()->whereKey($id)
            ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinutes(5)))
            ->where(fn ($q) => $q->whereNull('leased_until')->orWhere('leased_until', '<=', now()))
            ->update(['dispatched_at' => now()]);
        if ($claimed !== 1) {
            return false;
        }
        try {
            Bus::dispatch(new ProcessStudentTuitionPayment($id));
        } catch (Throwable $exception) {
            report($exception);
            StudentTuitionPaymentAttempt::query()->whereKey($id)->update(['last_error' => 'Queue unavailable; committed consent retained for scheduler recovery.']);
        }

        return true;
    }

    public function dispatch(): int
    {
        $count = 0;
        foreach (StudentTuitionPaymentAttempt::query()->whereNotNull('consent_digest')->whereNull('posted_at')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit(100)->get() as $attempt) {
            $count += (int) $this->dispatchOne($attempt->id);
        }

        return $count;
    }
}
