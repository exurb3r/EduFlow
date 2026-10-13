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
use Yukazakiri\Lepton\Contracts\AuthGateway;
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

test('an unauthenticated operator session is refused before the signer', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();

    $auth = Mockery::mock(AuthGateway::class);
    if (! $auth instanceof AuthGateway) {
        throw new RuntimeException('Auth mock does not implement gateway.');
    }
    mockExpectation($auth, 'isAuthenticatedFor')->with('ARC-TESTNET')->andReturn(false);
    app()->instance(AuthGateway::class, $auth);
    quietRail();

    $payment = billConsent($c, 5_000_000);
    app(StudentBillPaymentProcessor::class)->process($payment->id);

    expect($payment->fresh()->submitted_at)->toBeNull()
        ->and($payment->fresh()->last_error)->toContain('Operator testnet session unavailable');
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

test('the sweep redelivers owed work and never redelivers work with nothing left to advance', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();

    $payment = billConsent($c, 5_000_000);
    $dispatcher = app(StudentBillPaymentDispatcher::class);

    // Consent alone is not a submission, but a consented row IS owed work: the
    // lane may have been enabled after the student signed. Delivering it lets
    // the worker prepare it, and preparing reaches no signer.
    expect($dispatcher->dispatchDue())->toBe(1);
    Bus::assertDispatched(ProcessStudentBillPayment::class, fn (ProcessStudentBillPayment $job): bool => $job->paymentId === $payment->id);

    $payment->fresh()->update(['submission_state' => StudentBillPayment::SUBMISSION_PREPARED]);
    expect($dispatcher->dispatchDue())->toBe(1);

    Bus::assertDispatched(ProcessStudentBillPayment::class, fn (ProcessStudentBillPayment $job): bool => $job->paymentId === $payment->id);

    // A payment the rail already owns is the one that most needs another look:
    // dropping it here is what strands real money in an unverified state.
    $payment->fresh()->update(['submitted_at' => now(), 'submission_state' => StudentBillPayment::SUBMISSION_ACCEPTED]);
    expect($dispatcher->dispatchDue())->toBe(1);

    // Awaiting a person is not awaiting a worker: no amount of sweeping resolves it.
    $payment->fresh()->update(['submission_state' => StudentBillPayment::SUBMISSION_NEEDS_REVIEW]);
    expect($dispatcher->dispatchDue())->toBe(0);

    // And an allocated payment has nothing left to advance at all.
    $payment->fresh()->update(['submission_state' => StudentBillPayment::SUBMISSION_VERIFIED, 'allocated_at' => now()]);
    expect($dispatcher->dispatchDue())->toBe(0);
});

test('the sweep recovers every stage a worker could still advance and never a leased one', function (string $state, string $lease): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();

    $payment = billConsent($c, 5_000_000);
    $payment->update(['submission_state' => $state,
        'submitted_at' => in_array($state, StudentBillPayment::OBSERVABLE_STATES, true) ? now() : null,
        'leased_until' => $lease === 'live' ? now()->addMinutes(5) : null,
        'next_attempt_at' => $lease === 'live' ? null : now()->subMinute()]);

    $dispatched = app(StudentBillPaymentDispatcher::class)->dispatchDue();

    expect($dispatched)->toBe($lease === 'live' ? 0 : 1);
})->with([
    'consented after the lane was enabled' => [StudentBillPayment::SUBMISSION_CONSENTED, 'expired'],
    'prepared but not yet sent' => [StudentBillPayment::SUBMISSION_PREPARED, 'expired'],
    'signer may already have been reached' => [StudentBillPayment::SUBMISSION_SUBMITTING, 'expired'],
    'rail accepted, nobody observed again' => [StudentBillPayment::SUBMISSION_ACCEPTED, 'expired'],
    'hash exists, proof outstanding' => [StudentBillPayment::SUBMISSION_AWAITING_VERIFICATION, 'expired'],
    'verified, allocation still owed' => [StudentBillPayment::SUBMISSION_VERIFIED, 'expired'],
    'a live worker owns this one' => [StudentBillPayment::SUBMISSION_ACCEPTED, 'live'],
]);

test('the sweep leaves an accepted transfer alone while observation is switched off', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    config(['eduflow.student_bills.observe_enabled' => false]);
    allowPilot($c);
    Bus::fake();

    $payment = billConsent($c, 5_000_000);
    $payment->update(['submission_state' => StudentBillPayment::SUBMISSION_ACCEPTED, 'submitted_at' => now()]);

    // Reporting it as recovered would overstate the lane: nothing can read the
    // rail right now, so the payment stays exactly as stranded as it was.
    expect(app(StudentBillPaymentDispatcher::class)->dispatchDue())->toBe(0);
});

test('observation recovery stays available when new bill submissions are disabled', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    allowPilot($c);
    Bus::fake();
    $payment = billConsent($c);
    $payment->update(['submission_state' => StudentBillPayment::SUBMISSION_ACCEPTED, 'submitted_at' => now()]);
    config(['eduflow.student_bills.enabled' => false]);

    expect(app(StudentBillPaymentDispatcher::class)->dispatchDue())->toBe(1);
    Bus::assertDispatched(ProcessStudentBillPayment::class, fn (ProcessStudentBillPayment $job): bool => $job->paymentId === $payment->id);
    $this->artisan('eduflow:dispatch-student-bill-payments')->assertSuccessful();
    expect($payment->fresh()->submission_state)->toBe(StudentBillPayment::SUBMISSION_ACCEPTED);
});

test('stop switch does not strand verified bill allocation while observation is off', function (): void {
    $c = billPaymentContext();
    enableBillLane();
    Bus::fake();
    $payment = billConsent($c);
    $payment->update(['submission_state' => StudentBillPayment::SUBMISSION_VERIFIED]);
    config(['eduflow.student_bills.stop_switch' => true, 'eduflow.student_bills.observe_enabled' => false]);

    expect(app(StudentBillPaymentDispatcher::class)->dispatchDue())->toBe(1);
    Bus::assertDispatched(ProcessStudentBillPayment::class, fn (ProcessStudentBillPayment $job): bool => $job->paymentId === $payment->id);
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
