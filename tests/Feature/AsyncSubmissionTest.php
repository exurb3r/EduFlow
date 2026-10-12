<?php

declare(strict_types=1);

use App\Jobs\ProcessPaymentSubmission;
use App\Models\PaymentSubmissionAttempt;
use App\Models\PaymentSubmissionOutbox;
use App\Models\Transaction;
use App\Services\IsolatedPaymentExecutor;
use App\Services\Payments\AsyncPaymentTransport;
use App\Services\Payments\TransportAcceptance;
use App\Services\Payments\TransportResolution;
use App\Services\PaymentSettlementReconciler;

/**
 * The asynchronous rail.
 *
 * Circle agent wallets do not return an on-chain hash from a transfer; they
 * return a transaction id and settle later. These tests pin the three things
 * that makes safe: an accepted transfer records *no* reference, it is never
 * dispatchable again, and an in-flight payment is never mistaken for a settled
 * one in either direction.
 */

/**
 * A transport whose answers the test dictates.
 */
function scriptedTransport(callable $submit, callable $resolve): AsyncPaymentTransport
{
    $transport = Mockery::mock(AsyncPaymentTransport::class);

    if (! $transport instanceof AsyncPaymentTransport) {
        throw new RuntimeException('Mockery did not build a payment transport.');
    }

    mockExpectation($transport, 'submit')->andReturnUsing($submit);
    mockExpectation($transport, 'resolve')->andReturnUsing($resolve);
    $transport->shouldReceive('estimate')->andReturn(['data' => ['medium' => ['networkFee' => '0.0177']]]);
    app()->instance(AsyncPaymentTransport::class, $transport);

    return $transport;
}

test('an accepted transfer records a handle and no reference, because no hash exists yet', function (): void {
    [$entry] = executorEntry();

    scriptedTransport(
        fn (): TransportAcceptance => new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'),
        fn (): TransportResolution => TransportResolution::pending(),
    );

    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle',
        'eduflow.submission.transport' => 'circle_agent_wallet']);

    $outcome = app(IsolatedPaymentExecutor::class)->submit($entry);

    expect($outcome['outcome'])->toBe('accepted')
        ->and($outcome['provider_reference'])->toBeNull()
        ->and($outcome['provider_handle'])->toBe('116c7ce7-8f2f-53ac-8b06-f18aac151ce3')
        ->and($outcome['detail']['settlement_proven'])->toBeFalse();

    (new ProcessPaymentSubmission($entry->id))->handle(app(IsolatedPaymentExecutor::class));

    $entry->refresh();

    expect($entry->state)->toBe('accepted')
        ->and($entry->stage)->toBe('awaiting_rail_completion')
        ->and($entry->provider_handle)->toBe('116c7ce7-8f2f-53ac-8b06-f18aac151ce3')
        // The single most important assertion in this file: the reference
        // column stays empty, because a handle in it would be read as a hash
        // and would come back `not_found`, accusing a payment that is merely
        // in flight of having vanished.
        ->and($entry->result['provider_reference'])->toBeNull()
        ->and($entry->result['settled'])->toBeFalse()
        ->and(PaymentSubmissionAttempt::query()->firstOrFail()->outcome)->toBe('accepted');

    // No local ledger row may exist for a transfer that has not settled.
    expect(Transaction::query()->count())->toBe(0);
});

test('an accepted transfer is never dispatchable again', function (): void {
    // Resubmitting an in-flight payment is how a vendor gets paid twice. The
    // rail already owns it, so only observation may follow.
    expect(PaymentSubmissionOutbox::IN_FLIGHT_STATES)->toBe(['accepted', 'submitted'])
        ->and(PaymentSubmissionOutbox::DISPATCHABLE_STATES)->not->toContain('accepted')
        ->and(PaymentSubmissionOutbox::OPEN_STATES)->toContain('accepted');
});

test('a still-pending acceptance stays accepted and keeps its capacity', function (): void {
    [$entry] = executorEntry();
    $entry->state = 'accepted';
    $entry->provider_handle = '116c7ce7-8f2f-53ac-8b06-f18aac151ce3';
    $entry->result = ['provider_handle' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3', 'provider_reference' => null];
    $entry->save();

    scriptedTransport(
        fn (): TransportAcceptance => new TransportAcceptance('unused'),
        fn (): TransportResolution => TransportResolution::pending(),
    );

    config(['eduflow.submission.transport' => 'circle_agent_wallet']);

    $verdict = app(PaymentSettlementReconciler::class)->reconcile($entry->fresh());

    $entry->refresh();

    expect($verdict['settled'])->toBeFalse()
        ->and($entry->state)->toBe('accepted')
        ->and($entry->stage)->toBe('accepted_pending')
        // Still in flight, so still not something anyone may conclude about.
        ->and($entry->result['provider_reference'])->toBeNull()
        ->and(Transaction::query()->count())->toBe(0);
});

test('a rail that cannot be asked is unknown, never a failed transfer', function (): void {
    [$entry] = executorEntry();
    $entry->state = 'accepted';
    $entry->provider_handle = '116c7ce7-8f2f-53ac-8b06-f18aac151ce3';
    $entry->save();

    scriptedTransport(
        fn (): TransportAcceptance => new TransportAcceptance('unused'),
        fn (): TransportResolution => TransportResolution::unknown('Rail did not return this transaction.'),
    );

    config(['eduflow.submission.transport' => 'circle_agent_wallet']);

    $verdict = app(PaymentSettlementReconciler::class)->reconcile($entry->fresh());

    $entry->refresh();

    expect($verdict['verdict'])->toBe('unknown')
        ->and($entry->state)->toBe('accepted')
        ->and(Transaction::query()->count())->toBe(0);
});

test('a handle with no hash yet is still pending, not a completed transfer', function (): void {
    [$entry] = executorEntry();
    $entry->state = 'accepted';
    $entry->provider_handle = '116c7ce7-8f2f-53ac-8b06-f18aac151ce3';
    $entry->save();

    // Circle can report COMPLETE before it publishes a hash. Promoting that to
    // `submitted` would put an unusable value where a hash belongs.
    scriptedTransport(
        fn (): TransportAcceptance => new TransportAcceptance('unused'),
        fn (): TransportResolution => TransportResolution::completed('', ['state' => 'COMPLETE']),
    );

    config(['eduflow.submission.transport' => 'circle_agent_wallet']);

    app(PaymentSettlementReconciler::class)->reconcile($entry->fresh());

    expect($entry->fresh()->state)->toBe('accepted');
});

test('a completed transfer with a real hash is verified on chain and settles', function (): void {
    [$entry] = executorEntry();

    $hash = '0x'.str_repeat('f', 64);

    $entry->state = 'accepted';
    $entry->provider_handle = '116c7ce7-8f2f-53ac-8b06-f18aac151ce3';
    $entry->save();

    scriptedTransport(
        fn (): TransportAcceptance => new TransportAcceptance('unused'),
        fn (): TransportResolution => TransportResolution::completed($hash),
    );

    stubArc(settledReceiptFor($entry));

    config(['eduflow.submission.transport' => 'circle_agent_wallet']);

    $verdict = app(PaymentSettlementReconciler::class)->reconcile($entry->fresh());

    $entry->refresh();

    expect($verdict['verdict'])->toBe('verified')
        ->and($verdict['settled'])->toBeTrue()
        ->and($entry->state)->toBe('completed')
        ->and($entry->result['provider_reference'])->toBe($hash)
        // The handle survives so the transfer can be traced back to what the
        // rail was actually asked to do.
        ->and($entry->result['provider_handle'])->toBe('116c7ce7-8f2f-53ac-8b06-f18aac151ce3');
});

test('an accepted entry whose rail is no longer configured is not retried', function (): void {
    [$entry] = executorEntry();
    $entry->state = 'accepted';
    $entry->provider_handle = '116c7ce7-8f2f-53ac-8b06-f18aac151ce3';
    $entry->save();

    config(['eduflow.submission.transport' => 'synchronous']);

    $verdict = app(PaymentSettlementReconciler::class)->reconcile($entry->fresh());

    expect($verdict['verdict'])->toBe('unreadable')
        ->and($entry->fresh()->state)->toBe('accepted');
});
