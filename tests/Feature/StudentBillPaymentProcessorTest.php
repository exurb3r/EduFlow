<?php

declare(strict_types=1);

use App\Jobs\ProcessStudentBillPayment;
use App\Models\StudentBillPayment;
use App\Services\Payments\FeeGuarantee;
use App\Services\Payments\TransportResolution;
use App\Services\StudentBillPaymentDispatcher;
use App\Services\StudentBillPaymentProcessor;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

/**
 * The submit worker: the only component permitted to reach a signer.
 *
 * The property under test throughout is that a payment is sent at most once.
 * Every case here is a way the lane can be retried, delayed, duplicated or left
 * half-done, and in each one the answer must be the same: observe the transfer
 * that may already exist, never mint a second one.
 */
test('a consented bill is dispatched only after the consent commits', function (): void {
    $c = billPaymentContext();
    $owner = enableBillLane();
    allowPilot($c);
    Bus::fake();

    $payment = billConsent($c, 5_000_000);

    Bus::assertDispatched(ProcessStudentBillPayment::class, fn (ProcessStudentBillPayment $job): bool => $job->paymentId === $payment->id);
    expect($payment->submission_state)->toBe(StudentBillPayment::SUBMISSION_CONSENTED)
        ->and($payment->submitted_at)->toBeNull()
        ->and($owner->id)->toBe(config('eduflow.student_bills.recovery_owner_id'));
});

test('a disabled lane retains consent and never reaches a signer', function (): void {
    $c = billPaymentContext();
    config(['eduflow.student_bills.enabled' => false]);
    quietRail();
    Bus::fake();

    $payment = billConsent($c, 5_000_000);
    app(StudentBillPaymentProcessor::class)->process($payment->id);

    expect($payment->fresh()->submission_state)->toBe(StudentBillPayment::SUBMISSION_NEEDS_REVIEW)
        ->and($payment->fresh()->submitted_at)->toBeNull()
        ->and($payment->fresh()->last_error)->toContain('disabled or stopped');
});

test('the stop switch halts new sends while leaving an existing transfer recoverable', function (): void {
    $c = billPaymentContext();
    $owner = enableBillLane();
    allowPilot($c);
    Bus::fake();
    $payment = billConsent($c, 5_000_000);
    quietRail();

    config(['eduflow.student_bills.stop_switch' => true]);
    app(StudentBillPaymentProcessor::class)->process($payment->id);

    expect($payment->fresh()->submitted_at)->toBeNull()
        ->and($payment->fresh()->last_error)->toContain('disabled or stopped');
    // And the owner still stands: consent is not erased by a stop.
    expect($payment->fresh()->hasValidConsent())->toBeTrue();
});

test('one consent produces one send and one handle', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();
    acceptingRail();

    $payment = billConsent($c, 5_000_000);
    $processor = app(StudentBillPaymentProcessor::class);
    $processor->process($payment->id);
    $processor->process($payment->id);
    $processor->process($payment->id);

    $fresh = $payment->fresh();
    // The transport's `submit` is `once()`; a second call would fail the test.
    expect($fresh->submission_state)->toBe(StudentBillPayment::SUBMISSION_ACCEPTED)
        ->and($fresh->provider_handle)->toBe('116c7ce7-8f2f-53ac-8b06-f18aac151ce3')
        ->and($fresh->provider_reference)->toBeNull()
        ->and($fresh->submitted_at)->not->toBeNull();
});

test('a started submission can never return to a dispatchable state', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();
    $payment = billConsent($c, 5_000_000);
    $started = $payment->fresh();
    $started->update(['submitted_at' => now(), 'submission_state' => StudentBillPayment::SUBMISSION_SUBMITTING]);

    expect(fn () => $started->update(['submission_state' => StudentBillPayment::SUBMISSION_PREPARED]))
        ->toThrow(LogicException::class);

    expect($started->fresh()->submission_state)->toBe(StudentBillPayment::SUBMISSION_SUBMITTING);
});

test('a lost response becomes unknown and is never sent again', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();
    // One call, then a timeout: the payment may already exist on chain.
    sendingRail(fn () => throw new RuntimeException('timeout waiting for rail'));

    $payment = billConsent($c, 5_000_000);
    $processor = app(StudentBillPaymentProcessor::class);
    $processor->process($payment->id);
    $processor->process($payment->id);

    // The second pass observes, finds no handle to resolve, and parks the row.
    // That is the honest end state: the transfer may exist on chain, so a person
    // reconciles it -- and `submit` was `once()`, so nothing was sent twice.
    $fresh = $payment->fresh();
    expect($fresh->submission_state)->toBe(StudentBillPayment::SUBMISSION_NEEDS_REVIEW)
        ->and($fresh->submitted_at)->not->toBeNull()
        ->and($fresh->last_error)->toContain('do not send again');
});

test('a submission with no captured handle is parked for a person, never resent', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();
    quietRail();

    $payment = billConsent($c, 5_000_000);
    // A signer was reached but the handle never came back.
    $payment->fresh()->update(['submitted_at' => now(), 'submission_state' => StudentBillPayment::SUBMISSION_SUBMITTING]);

    app(StudentBillPaymentProcessor::class)->process($payment->id);

    expect($payment->fresh()->submission_state)->toBe(StudentBillPayment::SUBMISSION_NEEDS_REVIEW)
        ->and($payment->fresh()->last_error)->toContain('do not send again');
});

test('a pending resolution reschedules instead of escalating', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();
    acceptingRail(fn (): TransportResolution => TransportResolution::pending());

    $payment = billConsent($c, 5_000_000);
    $processor = app(StudentBillPaymentProcessor::class);
    $processor->process($payment->id);
    $processor->process($payment->id);

    $fresh = $payment->fresh();
    expect($fresh->submission_state)->toBe(StudentBillPayment::SUBMISSION_ACCEPTED)
        ->and($fresh->next_attempt_at)->not->toBeNull()
        ->and($fresh->provider_reference)->toBeNull();
});

test('a matching receipt proves the movement and allocates the bill once', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();
    $hash = '0x'.str_repeat('f', 64);
    acceptingRail(fn (): TransportResolution => TransportResolution::completed($hash));

    $payment = billConsent($c, 5_000_000);
    $stub = billSettlementStub($c, $payment->fresh());

    $processor = app(StudentBillPaymentProcessor::class);
    $processor->process($payment->id);
    $processor->process($payment->id);
    $processor->process($payment->id);

    $fresh = $payment->fresh();
    expect($fresh->submission_state)->toBe(StudentBillPayment::SUBMISSION_VERIFIED)
        ->and($fresh->provider_reference)->toBe($hash)
        ->and($fresh->allocated_at)->not->toBeNull()
        ->and($fresh->applied_base_units)->toBe(5_000_000)
        ->and($c['bill']->fresh()->paid_base_units)->toBe(5_000_000)
        // The sponsor paid the fee, so it is recorded but cannot refuse proof.
        ->and($fresh->fee_guarantee)->toBe(FeeGuarantee::RelayerSponsored->value)
        ->and($stub)->toBe($hash);
});

test('a bill outside the pilot allow-list is never sent', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    config(['eduflow.student_bills.pilot_bill_ids' => [999999]]);
    Bus::fake();
    quietRail();

    $payment = billConsent($c, 5_000_000);
    app(StudentBillPaymentProcessor::class)->process($payment->id);

    expect($payment->fresh()->submitted_at)->toBeNull()
        ->and($payment->fresh()->last_error)->toContain('not explicitly designated');
});

test('the payer may never be the recovery owner', function (): void {
    $c = billPaymentContext();
    Bus::fake();
    $payment = billConsent($c, 5_000_000);

    // The student's own account, given the finance role: a misconfiguration that
    // must still be refused, because consent is not recovery authority.
    $payer = $c['student']->user;
    $payer->assignRole(Role::findOrCreate('finance_officer', 'web'));
    config(['eduflow.student_bills.enabled' => true, 'eduflow.student_bills.observe_enabled' => true,
        'eduflow.student_bills.recovery_owner_id' => $payer->id,
        'eduflow.student_bills.pilot_bill_ids' => [$c['bill']->id]]);
    quietRail();

    app(StudentBillPaymentProcessor::class)->process($payment->id);

    expect($payment->fresh()->submitted_at)->toBeNull()
        ->and($payment->fresh()->last_error)->toContain('not the payer');
});

test('a rail with no fee bound at all is refused before the signer', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();
    quietRail(FeeGuarantee::Unbounded);

    $payment = billConsent($c, 5_000_000);
    app(StudentBillPaymentProcessor::class)->process($payment->id);

    expect($payment->fresh()->submitted_at)->toBeNull()
        ->and($payment->fresh()->last_error)->toContain('no mechanism at all');
});

test('a wallet that cannot cover the principal is refused before the signer', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();

    // Consent is taken while the wallet can still pay, then the balance drops
    // before the worker runs. This is the race that matters: a quote that was
    // affordable a minute ago is not authority to send now.
    $payment = billConsent($c, 5_000_000);
    app()->instance(ArcNetworkGateway::class, billArcGateway(1_000_000));
    quietRail();

    app(StudentBillPaymentProcessor::class)->process($payment->id);

    expect($payment->fresh()->submitted_at)->toBeNull()
        ->and($payment->fresh()->last_error)->toContain('cannot cover what this rail will debit');
});

test('a rehearsal reaches no signer and allocates nothing', function (): void {
    $c = billPaymentContext();
    config(['lepton.default' => 'fake']);
    $fake = new FakeLeptonGateway;
    $fake->seedBalance($c['student']->payout_address, 20_000_000);
    app()->instance(ArcNetworkGateway::class, $fake);
    app()->instance(WalletGateway::class, $fake);
    enableBillLane();
    allowPilot($c);
    Bus::fake();
    quietRail();

    $payment = billConsent($c, 5_000_000);
    app(StudentBillPaymentProcessor::class)->process($payment->id);

    $fresh = $payment->fresh();
    expect($fresh->simulated)->toBeTrue()
        ->and($fresh->submitted_at)->toBeNull()
        ->and($fresh->allocated_at)->toBeNull()
        ->and($c['bill']->fresh()->paid_base_units)->toBe(0)
        ->and($fresh->last_error)->toContain('Rehearsal');
});

test('the sweep redelivers owed work and skips anything already submitted', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();

    $payment = billConsent($c, 5_000_000);
    $dispatcher = app(StudentBillPaymentDispatcher::class);
    $processor = app(StudentBillPaymentProcessor::class);

    // Not dispatchable yet: consent alone is not a prepared submission.
    expect($dispatcher->dispatchDue())->toBe(0);

    $payment->fresh()->update(['submission_state' => StudentBillPayment::SUBMISSION_PREPARED]);
    expect($dispatcher->dispatchDue())->toBe(1);

    Bus::assertDispatched(ProcessStudentBillPayment::class, fn (ProcessStudentBillPayment $job): bool => $job->paymentId === $payment->id);

    $payment->fresh()->update(['submitted_at' => now(), 'submission_state' => StudentBillPayment::SUBMISSION_ACCEPTED]);
    expect($dispatcher->dispatchDue())->toBe(0);
});

test('a job that runs twice observes rather than resends', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();
    acceptingRail(fn (): TransportResolution => TransportResolution::pending());

    $payment = billConsent($c, 5_000_000);
    $processor = app(StudentBillPaymentProcessor::class);

    // The same job payload delivered twice, as a queue may do.
    $job = new ProcessStudentBillPayment((int) $payment->id);
    $job->handle($processor);
    $job->handle($processor);

    expect($payment->fresh()->submission_state)->toBe(StudentBillPayment::SUBMISSION_ACCEPTED)
        ->and($payment->fresh()->observation_count)->toBeGreaterThan(0);
});
