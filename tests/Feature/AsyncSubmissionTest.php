<?php

declare(strict_types=1);

use App\Actions\ReviewVendorPayment;
use App\Jobs\ProcessPaymentSubmission;
use App\Models\PaymentSubmissionAttempt;
use App\Models\PaymentSubmissionOutbox;
use App\Models\Transaction;
use App\Services\IsolatedPaymentExecutor;
use App\Services\Payments\TransportAcceptance;
use App\Services\Payments\TransportResolution;
use App\Services\PaymentSettlementReconciler;
use App\Services\PaymentSubmissionDispatch;
use Illuminate\Support\Facades\Bus;

/**
 * The asynchronous rail.
 *
 * Circle agent wallets do not return an on-chain hash from a transfer; they
 * return a transaction id and settle later. These tests pin the three things
 * that makes safe: an accepted transfer records *no* reference, it is never
 * dispatchable again, and an in-flight payment is never mistaken for a settled
 * one in either direction.
 */
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

test('scheduled reconciliation resolves an acknowledged payment without a new submission', function (string $state): void {
    [$entry] = executorEntry();
    $entry->update(['state' => $state, 'provider_handle' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3']);
    $transport = scriptedTransport(
        fn (): TransportAcceptance => throw new RuntimeException('Reconciliation must never submit.'),
        fn (): TransportResolution => TransportResolution::completed('0x'.str_repeat('f', 64)),
    );
    $transport->shouldNotReceive('submit');
    stubArc(settledReceiptFor($entry));
    config(['eduflow.submission.transport' => 'circle_agent_wallet', 'eduflow.submission.stop_switch' => true]);

    $this->artisan('eduflow:reconcile-payment-submissions')->assertSuccessful();

    expect($entry->fresh()->state)->toBe('completed')
        ->and($entry->fresh()->provider_handle)->toBe('116c7ce7-8f2f-53ac-8b06-f18aac151ce3')
        ->and(Transaction::query()->count())->toBe(0);
})->with(['accepted', 'unknown']);

test('provider timeout records exactly one unknown attempt and can never be sent again', function (): void {
    [$entry] = executorEntry();
    $submissions = 0;
    $transport = scriptedTransport(
        function () use (&$submissions): TransportAcceptance {
            $submissions++;

            throw new RuntimeException('Provider accepted, response lost.');
        },
        fn (): TransportResolution => TransportResolution::unknown('Handle unavailable.'),
    );
    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle',
        'eduflow.submission.transport' => 'circle_agent_wallet']);

    $job = new ProcessPaymentSubmission($entry->id);
    $job->handle(app(IsolatedPaymentExecutor::class));
    $job->handle(app(IsolatedPaymentExecutor::class));

    expect($entry->fresh()->state)->toBe('unknown')
        ->and($submissions)->toBe(1)
        ->and($entry->fresh()->attempts)->toBe(1)
        ->and($entry->fresh()->attempts()->count())->toBe(1)
        ->and($entry->fresh()->attempts()->firstOrFail()->response_snapshot['detail']['error'])->toContain('response lost')
        ->and($entry->fresh()->result['payments_submitted'])->toBeNull()
        ->and($entry->fresh()->evidence()['payments_submitted'])->toBeNull()
        ->and($entry->fresh()->reservation->isReleased())->toBeFalse();
});

test('duplicate queue delivery never reopens an in-flight or uncertain submission', function (string $state): void {
    [$entry] = executorEntry();
    $entry->update(['state' => $state]);
    $transport = scriptedTransport(
        fn (): TransportAcceptance => throw new RuntimeException('Duplicate delivery reached signer.'),
        fn (): TransportResolution => TransportResolution::pending(),
    );
    $transport->shouldNotReceive('submit');
    $transport->shouldNotReceive('estimate');
    config(['eduflow.submission.enabled' => true, 'lepton.default' => 'circle',
        'eduflow.submission.transport' => 'circle_agent_wallet']);

    (new ProcessPaymentSubmission($entry->id))->handle(app(IsolatedPaymentExecutor::class));

    expect($entry->fresh()->state)->toBe($state)
        ->and($entry->fresh()->attempts)->toBe(0);
})->with(['running', 'unknown', 'accepted', 'submitted']);

test('an uncertain payment with a known hash is observed again and never redispatched', function (): void {
    [$entry] = executorEntry();
    $entry->update(['state' => 'unknown', 'result' => ['provider_reference' => '0x'.str_repeat('f', 64)]]);
    stubArc(settledReceiptFor($entry));
    config(['eduflow.submission.enabled' => true, 'queue.default' => 'database']);
    Bus::fake();

    expect(app(PaymentSubmissionDispatch::class)->dispatch())->toBe(0);
    $this->artisan('eduflow:reconcile-payment-submissions')->assertSuccessful();

    Bus::assertNotDispatched(ProcessPaymentSubmission::class);
    expect($entry->fresh()->state)->toBe('completed');
});

test('a started submission cannot be rewound to queued even before a provider handle exists', function (): void {
    [$entry] = executorEntry();
    $entry->update(['state' => 'running', 'attempts' => 1]);

    expect(fn () => $entry->update(['state' => 'queued']))->toThrow(LogicException::class);
});

test('uncertain submission without an identifier cannot starve a newer accepted transfer', function (): void {
    [$lost, $c] = executorEntry();
    $lost->update(['state' => 'unknown']);
    $this->travel(31)->seconds();
    $c['hold'] = reserveSecondAuthorizationBill($c);
    $authorization = app(ReviewVendorPayment::class)->handle($c['reviewer'], $c['drafts'][1], paymentAuthorizationInput($c, index: 1));
    $entry = PaymentSubmissionOutbox::query()->where('payment_authorization_id', $authorization->id)->firstOrFail();
    $entry->update(['state' => 'accepted', 'provider_handle' => '116c7ce7-8f2f-53ac-8b06-f18aac151ce3']);
    scriptedTransport(fn (): TransportAcceptance => throw new RuntimeException('Reconciliation must not submit.'),
        fn (): TransportResolution => TransportResolution::completed('0x'.str_repeat('f', 64)));
    stubArc(settledReceiptFor($entry));
    config(['eduflow.submission.transport' => 'circle_agent_wallet']);

    $this->artisan('eduflow:reconcile-payment-submissions', ['--limit' => 1])->assertSuccessful();

    expect($lost->fresh()->state)->toBe('unknown')->and($entry->fresh()->state)->toBe('completed');
});
