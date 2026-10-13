<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('eduflow:dispatch-finance-workflows')->everyMinute()->withoutOverlapping(2)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.background_finance.enabled', false));

Schedule::command('eduflow:dispatch-student-tuition')->everyMinute()->withoutOverlapping(5)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.student_tuition.enabled', false) || (bool) config('eduflow.student_tuition.observe_enabled', false));

// Recovery for settled tuition movements no receipt covers yet. The wake inside
// the posting commit is the normal path; this sweep only redelivers what a
// broker outage or a flag that was off at posting time left behind.
Schedule::command('eduflow:reconcile-tuition-receipts')->everyFiveMinutes()->withoutOverlapping(5)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.student_tuition.auto_intake', false));

Schedule::command('eduflow:reconcile-student-bill-receipts')->everyFiveMinutes()->withoutOverlapping(5)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.student_bills.auto_intake', false));

// Recovery for consented bill payments nobody took. The wake inside the consent
// transaction is the normal path; this sweep only redelivers what an outage or a
// disabled lane left behind, and it can never create a second transfer.
Schedule::command('eduflow:dispatch-student-bill-payments')->everyMinute()->withoutOverlapping(5)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.student_bills.enabled', false) || (bool) config('eduflow.student_bills.observe_enabled', false));

// Durably owed submissions are handed to a worker only when the submission
// runtime is explicitly enabled. Overlap control matters here: two ticks
// racing on the same row would be the difference between one owed payment and
// two attempts at it.
Schedule::command('eduflow:dispatch-payment-submissions')->everyMinute()->withoutOverlapping(2)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.submission.enabled', false));

Schedule::command('eduflow:dispatch-payment-submissions --recover-stale')->everyFiveMinutes()->withoutOverlapping(2)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.submission.enabled', false));

// Reconciliation is what turns a provider reference into a proven settlement,
// so it runs on its own schedule and without `--mirror`: reading the chain to
// decide whether money moved must not be coupled to writing local ledger rows.
// Mirroring stays an explicit operator action, because a ledger row is a
// record the institution is choosing to keep.
Schedule::command('eduflow:reconcile-payment-submissions')->everyMinute()->withoutOverlapping(2)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.submission.enabled', false));

// The autonomous lane is sessionless and never touches a rail: this tick reads
// live evidence, classifies due obligations and records a disposition. It is
// gated on the mandate runtime so a schedule left in place cannot enable the
// lane by itself. Overlap control matters because two ticks classifying the
// same bill would race on the occurrence identity, not on the money.
Schedule::command('eduflow:dispatch-mandate-occurrences')->everyMinute()->withoutOverlapping(2)->onOneServer()
    ->when(fn (): bool => (bool) config('eduflow.mandate.runtime_enabled', false));
