<?php

declare(strict_types=1);

use App\Actions\ReviewVendorPayment;
use App\Jobs\ProcessPaymentSubmission;
use App\Models\PaymentAuthorization;
use App\Models\PaymentSubmissionAttempt;
use App\Models\PaymentSubmissionOutbox;
use App\Models\Transaction;
use App\Services\IsolatedPaymentExecutor;
use App\Services\PaymentSubmissionDispatch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\travel;

/**
 * Durable submission work.
 *
 * The properties under test are the ones an executor will depend on and must
 * not have to fix later: work exists before anything is dispatched, identity
 * is stable across replays, an expired approval is never submitted on its
 * original authority, and nothing in this build ever produces a settlement.
 *
 * @param  array<string, mixed>  $c
 */
function authorizeSubmittedPayment(array $c, int $index = 0): PaymentAuthorization
{
    return app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][$index], paymentAuthorizationInput($c));
}

test('an approved payment is durably owed before anything is dispatched', function (): void {
    $c = paymentAuthorizationContext();
    $authorization = authorizeSubmittedPayment($c);

    $entry = PaymentSubmissionOutbox::query()->where('payment_authorization_id', $authorization->id)->firstOrFail();

    expect($entry->hasValidSnapshot())->toBeTrue()
        ->and($entry->state)->toBe('queued')
        ->and($entry->dispatched_at)->toBeNull()
        ->and($entry->attempts)->toBe(0)
        ->and($entry->provider_idempotency_key)->toBe('eduflow:'.$entry->request_key)
        ->and($entry->snapshot['authorization_digest'])->toBe($authorization->snapshot_digest)
        ->and($entry->snapshot['intent_digest'])->toBe($c['drafts'][0]->snapshot_digest);

    // Recording the obligation is not authority to move funds.
    expect($entry->evidence())->toMatchArray([
        'can_execute' => false,
        'payments_submitted' => 0,
        'external_funds_locked' => false,
        'local_accounts_changed' => false,
    ])->and(Transaction::query()->count())->toBe(0);
});

test('submission job uses the durable finance queue and waits for commit', function (): void {
    config(['eduflow.submission.queue_connection' => 'database', 'eduflow.submission.queue' => 'finance-submission']);
    $job = new ProcessPaymentSubmission(1);

    expect($job->connection)->toBe('database')
        ->and($job->queue)->toBe('finance-submission')
        ->and($job->afterCommit)->toBeTrue();
});

test('the submission identity is stable so a replay cannot mint a second owed payment', function (): void {
    $c = paymentAuthorizationContext();
    $input = paymentAuthorizationInput($c);
    $authorization = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input);

    $first = PaymentSubmissionOutbox::query()->where('payment_authorization_id', $authorization->id)->firstOrFail();

    // Replaying the same review is idempotent by request key: one
    // authorization, one owed payment, one provider identity.
    app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0], $input);

    expect(PaymentAuthorization::query()->count())->toBe(1)
        ->and(PaymentSubmissionOutbox::query()->count())->toBe(1)
        ->and(PaymentSubmissionOutbox::query()->firstOrFail()->provider_idempotency_key)->toBe($first->provider_idempotency_key)
        ->and(ReviewVendorPayment::submissionKey($authorization))
        ->toBe(ReviewVendorPayment::submissionKey($authorization->fresh()));
});

test('a held payment is never queued', function (): void {
    $c = paymentAuthorizationContext();

    $authorization = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][0],
        paymentAuthorizationInput($c, 'hold_payment'));

    expect($authorization->decision)->toBe('hold_payment')
        ->and(PaymentSubmissionOutbox::query()->count())->toBe(0);
});

test('a simulated authorization is rehearsal and never becomes submission work', function (): void {
    $c = paymentAuthorizationContext('filament_app', fake: true);

    $authorization = authorizeSubmittedPayment($c);

    expect($authorization->snapshot['is_fake'])->toBeTrue()
        ->and($authorization->evidence()['payment_approved'])->toBeFalse()
        ->and($authorization->evidence()['authority_scope'])->toBe('simulation_only')
        ->and(PaymentSubmissionOutbox::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});

test('the dispatch runtime is off by default and refuses a synchronous queue', function (): void {
    $c = paymentAuthorizationContext();
    authorizeSubmittedPayment($c);

    // Off by default, even on a durable queue.
    config(['queue.default' => 'database']);
    expect(fn () => app(PaymentSubmissionDispatch::class)->dispatch())->toThrow(ValidationException::class);

    // And still refused when switched on over a request-bound connection,
    // because a synchronous dispatch would defeat the point of an outbox.
    config(['eduflow.submission.enabled' => true, 'queue.default' => 'sync']);
    expect(fn () => app(PaymentSubmissionDispatch::class)->dispatch())->toThrow(ValidationException::class);
});

test('an enabled runtime dispatches owed work and records no transfer', function (): void {
    $c = paymentAuthorizationContext();
    authorizeSubmittedPayment($c);
    config(['eduflow.submission.enabled' => true, 'queue.default' => 'database']);

    Bus::fake();

    expect(app(PaymentSubmissionDispatch::class)->dispatch())->toBe(1)
        ->and(app(PaymentSubmissionDispatch::class)->dispatch())->toBe(1);

    Bus::assertDispatchedTimes(ProcessPaymentSubmission::class, 2);
    expect(Transaction::query()->count())->toBe(0);
});

test('the worker records one attempt and holds the payment when the runtime is off', function (): void {
    $c = paymentAuthorizationContext();
    $entry = PaymentSubmissionOutbox::query()
        ->where('payment_authorization_id', authorizeSubmittedPayment($c)->id)
        ->firstOrFail();

    (new ProcessPaymentSubmission($entry->id))->handle(app(IsolatedPaymentExecutor::class));
    $entry->refresh();

    expect($entry->state)->toBe('blocked')
        ->and($entry->stage)->toBe('runtime_disabled')
        ->and($entry->attempts)->toBe(1)
        ->and($entry->last_error)->toContain('not enabled for this installation')
        ->and($entry->result['settled'])->toBeFalse()
        ->and($entry->result['can_execute'])->toBeFalse();

    $attempt = PaymentSubmissionAttempt::query()->where('payment_submission_outbox_id', $entry->id)->firstOrFail();

    expect($attempt->hasValidEvidence($entry))->toBeTrue()
        ->and($attempt->attempt_number)->toBe(1)
        ->and($attempt->outcome)->toBe('blocked')
        ->and($attempt->provider_idempotency_key)->toBe($entry->provider_idempotency_key)
        ->and($attempt->provider_reference)->toBeNull()
        ->and($attempt->evidence()['settled'])->toBeFalse()
        ->and(Transaction::query()->count())->toBe(0);
});

test('an expired approval is never submitted on its original authority', function (): void {
    $c = paymentAuthorizationContext();
    $entry = PaymentSubmissionOutbox::query()
        ->where('payment_authorization_id', authorizeSubmittedPayment($c)->id)
        ->firstOrFail();

    // The entry stays durably owed, but its authority is gone.
    travel(6)->minutes();

    (new ProcessPaymentSubmission($entry->id))->handle(app(IsolatedPaymentExecutor::class));
    $entry->refresh();

    expect($entry->state)->toBe('blocked')
        ->and($entry->stage)->toBeIn(['approval_expired', 'authorization_stale'])
        // Whatever the reason, the worker never reached an executor.
        ->and($entry->last_error)->not->toContain('executor')
        ->and($entry->result['settled'])->toBeFalse()
        ->and(PaymentSubmissionOutbox::query()->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(0);
});

test('the stop switch holds new submissions without erasing evidence or the hold', function (): void {
    $c = paymentAuthorizationContext();
    $entry = PaymentSubmissionOutbox::query()
        ->where('payment_authorization_id', authorizeSubmittedPayment($c)->id)
        ->firstOrFail();

    config(['eduflow.submission.stop_switch' => true]);

    (new ProcessPaymentSubmission($entry->id))->handle(app(IsolatedPaymentExecutor::class));
    $entry->refresh();

    expect($entry->stage)->toBe('stop_switch')
        ->and($entry->last_error)->toContain('evidence are preserved')
        ->and($c['hold']->fresh()->isReleased())->toBeFalse()
        ->and(Transaction::query()->count())->toBe(0);
});

test('an expired worker lease reopens as unknown rather than being retried blindly', function (): void {
    $c = paymentAuthorizationContext();
    $entry = PaymentSubmissionOutbox::query()
        ->where('payment_authorization_id', authorizeSubmittedPayment($c)->id)
        ->firstOrFail();
    config(['eduflow.submission.enabled' => true, 'queue.default' => 'database']);

    $entry->state = 'running';
    $entry->heartbeat_at = Carbon::now()->subHour();
    $entry->save();

    expect(app(PaymentSubmissionDispatch::class)->recoverStaleLeases())->toBe(1);

    $entry->refresh();

    expect($entry->state)->toBe('unknown')
        ->and($entry->stage)->toBe('lease_expired')
        ->and($entry->last_error)->toContain('Reconcile the existing attempt before any retry');

    // Unknown is neither settled nor failed, and it keeps the same identity.
    expect($entry->evidence()['can_execute'])->toBeFalse()
        ->and($entry->provider_idempotency_key)->toBe('eduflow:'.$entry->request_key);
});

test('outbox identity is immutable and a concluded entry cannot be re-decided', function (): void {
    $c = paymentAuthorizationContext();
    $entry = PaymentSubmissionOutbox::query()
        ->where('payment_authorization_id', authorizeSubmittedPayment($c)->id)
        ->firstOrFail();

    (new ProcessPaymentSubmission($entry->id))->handle(app(IsolatedPaymentExecutor::class));
    $entry->refresh();

    $entry->request_key = (string) Str::uuid();
    expect(fn () => $entry->save())->toThrow(LogicException::class);

    $entry->refresh();
    $entry->state = 'queued';
    expect(fn () => $entry->save())->toThrow(LogicException::class);

    $entry->refresh();
    expect(fn () => $entry->delete())->toThrow(LogicException::class);

    $attempt = PaymentSubmissionAttempt::query()->where('payment_submission_outbox_id', $entry->id)->firstOrFail();
    $attempt->outcome = 'submitted';
    expect(fn () => $attempt->save())->toThrow(LogicException::class);
});

test('an invented state and a fabricated attempt outcome are refused at the database', function (): void {
    $c = paymentAuthorizationContext();
    $entry = PaymentSubmissionOutbox::query()
        ->where('payment_authorization_id', authorizeSubmittedPayment($c)->id)
        ->firstOrFail();

    expect(fn () => DB::table('payment_submission_outboxes')->where('id', $entry->id)
        ->update(['state' => 'invented_success']))->toThrow(QueryException::class);

    expect(fn () => DB::table('payment_submission_attempts')->insert([
        'organization_id' => $c['institution']->id, 'payment_submission_outbox_id' => $entry->id,
        'attempt_number' => 1, 'provider_idempotency_key' => $entry->provider_idempotency_key,
        'outcome' => 'settled_successfully', 'provider_reference' => '0xdeadbeef',
        'request_snapshot' => '{}', 'observed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
