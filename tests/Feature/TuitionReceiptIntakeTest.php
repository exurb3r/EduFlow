<?php

declare(strict_types=1);

use App\Actions\ImportTuitionPaymentAsCollection;
use App\Jobs\ReconcileTuitionReceipt;
use App\Models\CollectionBatch;
use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Services\Payments\FeeBoundedPaymentTransport;
use App\Services\Payments\TransportAcceptance;
use App\Services\Payments\TransportResolution;
use App\Services\StudentTuitionPaymentProcessor;
use App\Services\TuitionReceiptIntake;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

/**
 * Unattended reconciliation of a settled movement into Collections (PLAN §14.10 B1 leftover).
 *
 * The whole point of this lane is that automation must be stricter than the
 * staff button beside it, so each case is a boundary: the flag is off by
 * default, a legacy verification flag is refused, a missing or improper owner
 * is refused, the identity is derived rather than generated, and nothing here
 * turns a reconciliation into reviewed cash or a payment.
 */

/** Enable intake with a proper owner on top of the shared consent context. */
function intakeEnabled(): User
{
    $owner = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    config(['eduflow.student_tuition.auto_intake' => true, 'eduflow.student_tuition.intake_owner_id' => $owner->id]);

    return $owner;
}

/**
 * Drive one consented attempt all the way to a posted settlement.
 *
 * @param  array{student: Student, account: TuitionAccount, transport: FeeBoundedPaymentTransport&MockInterface, arc: ArcNetworkGateway&MockInterface}  $c
 */
function postSettledPayment(array $c, int $amount = 5_000000): StudentTuitionPayment
{
    $a = confirmConsent($c);
    $hash = '0x'.str_repeat('f', 64);
    mockExpectation($c['transport'], 'submit')->once()->andReturn(new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'));
    mockExpectation($c['transport'], 'resolve')->andReturn(TransportResolution::completed($hash));
    mockExpectation($c['arc'], 'rpc')->with('eth_getTransactionReceipt', [$hash])->andReturn([
        'transactionHash' => $hash, 'blockNumber' => '0x64', 'status' => '0x1', 'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1', 'logs' => []]);
    mockExpectation($c['arc'], 'rpc')->with('eth_getTransactionByHash', [$hash])->andReturn([
        'from' => $a->payer_address, 'to' => $a->recipient_address,
        'value' => '0x'.BigInteger::of($amount)->multipliedBy('1000000000000')->toBase(16), 'blockNumber' => '0x64']);
    mockExpectation($c['arc'], 'rpc')->with('eth_getBlockByNumber', ['0x64', false])->andReturn(['hash' => '0x'.str_repeat('a', 64)]);
    $processor = app(StudentTuitionPaymentProcessor::class);
    $processor->process($a->id);
    $processor->process($a->id);

    return StudentTuitionPayment::query()->where('request_key', $a->request_key)->sole();
}

test('a settled movement is not imported while automatic intake is off', function (): void {
    $c = consentContext();
    Bus::fake();
    $payment = postSettledPayment($c);

    expect(CollectionBatch::query()->count())->toBe(0)
        ->and(fn () => app(TuitionReceiptIntake::class)->reconcile($payment))->toThrow(ValidationException::class, 'disabled');
});

test('an enabled lane reconciles the posted movement exactly once, unreviewed', function (): void {
    $c = consentContext();
    $owner = intakeEnabled();
    Bus::fake();
    $payment = postSettledPayment($c);

    $intake = app(TuitionReceiptIntake::class);
    $batch = $intake->reconcile($payment);

    expect($batch->hasValidSnapshot())->toBeTrue()
        ->and($batch->isMovementSourced())->toBeTrue()
        ->and($batch->student_tuition_payment_id)->toBe($payment->getKey())
        ->and($batch->currency)->toBe('USDC')
        ->and($batch->received_minor_units)->toBe(5_000000)
        ->and($batch->prepared_by)->toBe($owner->id)
        // Reconciling is bookkeeping: no second arrival, no float credit.
        ->and(Transaction::query()->count())->toBe(0)
        // And it creates no authority: unreviewed, so it supplies no cash.
        ->and($batch->evidence()['receipts_reviewed'])->toBeFalse()
        ->and($batch->evidence()['unrestricted_minor_units'])->toBe('0');
});

test('the capture identity is derived from the movement, so replay is the same receipt', function (): void {
    $c = consentContext();
    intakeEnabled();
    Bus::fake();
    $payment = postSettledPayment($c);
    $intake = app(TuitionReceiptIntake::class);

    $first = $intake->reconcile($payment);
    $replay = $intake->reconcile($payment->fresh());
    $worker = new ReconcileTuitionReceipt((int) $payment->getKey());

    expect($intake->captureKey($payment))->toBe($first->capture_key)
        ->and($replay->is($first))->toBeTrue()
        ->and(CollectionBatch::query()->count())->toBe(1)
        // A fresh identity for the same movement is a duplicate, not a receipt.
        ->and(fn () => app(ImportTuitionPaymentAsCollection::class)->handle(
            User::query()->findOrFail(config('eduflow.student_tuition.intake_owner_id')), $payment, (string) Str::uuid()))
        ->toThrow(ValidationException::class, 'already reconciled')
        ->and($worker->paymentId)->toBe($payment->getKey());
});

test('a movement with no verified attempt proof is refused, unlike the staff button', function (): void {
    $c = consentContext();
    intakeEnabled();
    Bus::fake();
    $payment = postSettledPayment($c);
    // The pre-hardening shape: a verification flag with no consent or proof. The
    // staff button still offers this record, which is exactly why unattended
    // intake refuses it — the planner would reject it afterwards anyway.
    StudentTuitionPayment::query()->whereKey($payment->getKey())->update([
        'student_tuition_payment_attempt_id' => null]);

    expect(fn () => app(TuitionReceiptIntake::class)->reconcile($payment->fresh()))
        ->toThrow(ValidationException::class, 'legacy verification flag is not enough');
});

test('a tampered settlement proof stops intake', function (): void {
    $c = consentContext();
    intakeEnabled();
    Bus::fake();
    $payment = postSettledPayment($c);
    // The attempt's proof is write-once through the model, so the probe points
    // the integrity check at altered evidence directly.
    $probe = StudentTuitionPaymentAttempt::query()->findOrFail($payment->student_tuition_payment_attempt_id)->replicate();
    $proof = $probe->settlement_evidence;
    $proof['amount_base_units'] = 9_000000;
    $probe->setRawAttributes(array_merge($probe->getAttributes(), ['settlement_evidence' => json_encode($proof)]));

    expect($probe->hasValidSettlement())->toBeFalse()
        ->and(StudentTuitionPaymentAttempt::query()->findOrFail($payment->student_tuition_payment_attempt_id)->hasValidSettlement())->toBeTrue();
});

test('an unnamed or improper intake owner is refused rather than defaulted', function (): void {
    $c = consentContext();
    Bus::fake();
    $payment = postSettledPayment($c);
    /** @var TuitionReceiptIntake $intake */
    $intake = app(TuitionReceiptIntake::class);

    config(['eduflow.student_tuition.auto_intake' => true, 'eduflow.student_tuition.intake_owner_id' => null]);
    expect(fn () => $intake->reconcile($payment))->toThrow(ValidationException::class, 'verified finance intake owner');

    $student = $c['student']->user;
    config(['eduflow.student_tuition.intake_owner_id' => $student->id]);
    expect(fn () => $intake->reconcile($payment))->toThrow(ValidationException::class, 'verified finance intake owner');

    // The realistic misconfiguration: the payer's own account given the intake role.
    $student->assignRole(Role::findOrCreate('finance_officer', 'web'));
    expect(fn () => $intake->reconcile($payment))->toThrow(ValidationException::class, 'cannot be the payer');
});

test('the posting commit wakes intake, and a second wake replays the same receipt', function (): void {
    $c = consentContext();
    intakeEnabled();
    Bus::fake();
    postSettledPayment($c);

    // afterCommit fires the wake only once the transaction landed.
    expect(CollectionBatch::query()->count())->toBe(1);

    app(TuitionReceiptIntake::class)->wakeAfterCommit((int) CollectionBatch::query()->value('student_tuition_payment_id'));

    expect(CollectionBatch::query()->count())->toBe(1);
});

test('a refused intake leaves the settlement committed, unreconciled and recoverable', function (): void {
    $c = consentContext();
    $owner = intakeEnabled();
    Bus::fake();
    $payment = postSettledPayment($c);
    CollectionBatch::query()->where('student_tuition_payment_id', $payment->getKey())->delete();
    $intake = app(TuitionReceiptIntake::class);
    $import = app(ImportTuitionPaymentAsCollection::class);

    // The owner is removed after posting: the settlement stands, the receipt
    // does not, and the sweep must still find the payment as owed work.
    config(['eduflow.student_tuition.intake_owner_id' => null]);
    expect(fn () => $intake->reconcile($payment->fresh()))->toThrow(ValidationException::class, 'intake owner')
        ->and($payment->fresh()->collections()->exists())->toBeFalse()
        ->and($intake->pending($import)->pluck('id')->all())->toBe([$payment->getKey()]);

    // Naming a valid owner again lets the same deterministic identity complete.
    config(['eduflow.student_tuition.intake_owner_id' => $owner->id]);
    expect($intake->reconcile($payment->fresh())->hasValidSnapshot())->toBeTrue()
        ->and($intake->pending($import)->pluck('id')->all())->toBe([]);
});

test('the sweep dispatches owed work and is silent while the lane is disabled', function (): void {
    $c = consentContext();
    Bus::fake();
    $payment = postSettledPayment($c);
    $intake = app(TuitionReceiptIntake::class);

    expect($intake->dispatchDue())->toBe(0);

    $owner = intakeEnabled();
    expect($intake->dispatchDue())->toBe(1)
        ->and($owner->id)->toBe(config('eduflow.student_tuition.intake_owner_id'));
    Bus::assertDispatched(ReconcileTuitionReceipt::class, fn (ReconcileTuitionReceipt $job): bool => $job->paymentId === $payment->getKey());
});
