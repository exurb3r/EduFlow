<?php

declare(strict_types=1);

use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\StudentTuitionPaymentIntent;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Services\Payments\AsyncPaymentTransport;
use App\Services\Payments\FeeGuarantee;
use App\Services\Payments\TransportAcceptance;
use App\Services\Payments\TransportResolution;
use App\Services\StudentTuitionPaymentProcessor;
use App\Services\StudentTuitionPaymentService;
use Brick\Math\BigInteger;
use Illuminate\Validation\ValidationException;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

/** Existing tuition regression cases, adapted to immutable quote/confirm and async processing. */
test('the quote reports the amount, what is owed, and the destination', function (): void {
    $c = consentContext();
    $q = app(StudentTuitionPaymentService::class)->quote($c['student'], $c['account'], 5_000000);
    expect($q['amount_base_units'])->toBe(5_000000)->and($q['remaining_base_units'])->toBe(100_000000)
        ->and($q['recipient'])->toBe('0x'.str_repeat('2', 40))->and($q['chain'])->toBe('ARC-TESTNET')
        ->and($q['available_base_units'])->toBe(20_000000);
});

test('the quote refuses an amount larger than the wallet can cover', function (): void {
    $c = consentContext();
    expect(fn () => app(StudentTuitionPaymentService::class)->quote($c['student'], $c['account'], 20_000000))
        ->toThrow(ValidationException::class, 'plus the network fee ceiling');
});

test('a sponsored rail quotes spending the whole balance instead of failing the pay screen', function (): void {
    $c = consentContext();
    // The fixture funds exactly 20 USDC. Where Circle sponsors the gas the payer's
    // debit is that 20 USDC principal alone, so the quote is affordable and must be
    // issued. The stored snapshot used to demand principal + fee ceiling
    // (20.029452 USDC) from the same wallet, so the quote the service had already
    // accepted was rejected on insert and surfaced as an HTTP 500 on the pay
    // dialog rather than as a validation message the student could act on.
    app()->instance(AsyncPaymentTransport::class, tuitionTransport(FeeGuarantee::RelayerSponsored));

    $q = app(StudentTuitionPaymentService::class)->quote($c['student'], $c['account'], 20_000000);

    expect($q['amount_base_units'])->toBe(20_000000)
        ->and($q['available_base_units'])->toBe(20_000000)
        // The notional total still exceeds the balance; that is now recorded as
        // evidence rather than used as the affordability test.
        ->and($q['total_base_units'])->toBe(20_029452)
        ->and($q['payer_debit_base_units'])->toBe(20_000000)
        ->and($q['fee_paid_by_payer'])->toBeFalse()
        ->and($q['payer_debit'])->toBe('20.000000');

    expect(StudentTuitionPaymentIntent::query()->where('request_key', $q['request_key'])->firstOrFail()->hasValidSnapshot())->toBeTrue();
});

test('a fee the rail cannot read is never reported as free', function (): void {
    $c = consentContext();
    $transport = Mockery::mock(AsyncPaymentTransport::class);
    $transport->shouldReceive('estimate')->andReturn(['networkFee' => 'broken']);
    app()->instance(AsyncPaymentTransport::class, $transport);
    expect(fn () => app(StudentTuitionPaymentService::class)->quote($c['student'], $c['account'], 5_000000))
        ->toThrow(ValidationException::class, 'never assumed free');
});

test('the quote refuses an amount larger than what is owed', function (): void {
    $c = consentContext();
    expect(fn () => app(StudentTuitionPaymentService::class)->quote($c['student'], $c['account'], 200_000000))
        ->toThrow(ValidationException::class, 'remaining balance');
});

test('the recipient always comes from configuration, never from the request', function (): void {
    $c = consentContext();
    $q = app(StudentTuitionPaymentService::class)->quote($c['student'], $c['account'], 5_000000);
    expect($q['recipient'])->toBe(config('lepton.arc.treasury'));
});

test('paying is refused for a student with no generated wallet', function (): void {
    $c = consentContext();
    $other = Student::factory()->withoutPayoutAddress()->create();
    $account = TuitionAccount::factory()->create(['student_id' => $other->id, 'total_amount' => 10_000000, 'paid_amount' => 0]);
    expect(fn () => app(StudentTuitionPaymentService::class)->quote($other, $account, 5_000000))
        ->toThrow(ValidationException::class, 'generated operator-controlled');
    expect(StudentTuitionPayment::query()->count())->toBe(0);
});

test('paying is refused when the payout address is no longer the generated wallet', function (): void {
    $c = consentContext();
    $c['student']->update(['payout_address' => '0x'.str_repeat('9', 40)]);
    expect(fn () => app(StudentTuitionPaymentService::class)->quote($c['student'], $c['account'], 5_000000))
        ->toThrow(ValidationException::class, 'match the student address');
});

test('an account that is already settled is refused', function (): void {
    $c = consentContext();
    $c['account']->update(['paid_amount' => $c['account']->total_amount]);
    expect(fn () => app(StudentTuitionPaymentService::class)->quote($c['student'], $c['account'], 5_000000))
        ->toThrow(ValidationException::class, 'remaining balance');
});

test('another students tuition account cannot be paid', function (): void {
    $c = consentContext();
    $account = TuitionAccount::factory()->create();
    expect(fn () => app(StudentTuitionPaymentService::class)->quote($c['student'], $account, 5_000000))
        ->toThrow(ValidationException::class);
});

test('a failed transfer records nothing', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    mockExpectation($c['transport'], 'submit')->once()->andThrow(new RuntimeException('Provider error'));
    app(StudentTuitionPaymentProcessor::class)->process($a->id);
    expect($a->fresh()->state)->toBe('unknown')->and(StudentTuitionPayment::query()->count())->toBe(0)
        ->and($c['account']->fresh()->paid_amount)->toBe(0)->and(Transaction::query()->count())->toBe(0);
});

/** @param array<string, mixed> $c @param array<string, mixed> $overrides */
function tuitionReceiptStub(array $c, StudentTuitionPaymentAttempt $a, int $amount = 5_000000, array $overrides = []): void
{
    $hash = '0x'.str_repeat('f', 64);
    mockExpectation($c['transport'], 'submit')->once()->andReturn(new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'));
    $c['transport']->shouldReceive('resolve')->andReturn(TransportResolution::completed($hash));
    mockExpectation($c['arc'], 'rpc')->with('eth_getTransactionReceipt', [$hash])->andReturn([
        'transactionHash' => $hash, 'blockNumber' => '0x64', 'status' => '0x1', 'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1', 'logs' => [], ...$overrides]);
    mockExpectation($c['arc'], 'rpc')->with('eth_getTransactionByHash', [$hash])->andReturn([
        'from' => $a->payer_address, 'to' => $a->recipient_address,
        'value' => '0x'.BigInteger::of($amount)->multipliedBy('1000000000000')->toBase(16), 'blockNumber' => '0x64']);
    mockExpectation($c['arc'], 'rpc')->with('eth_getBlockByNumber', ['0x64', false])->andReturn(['hash' => '0x'.str_repeat('a', 64)]);
}

test('a payment is only recorded once the chain says the exact amount moved', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    tuitionReceiptStub($c, $a);
    $p = app(StudentTuitionPaymentProcessor::class);
    $p->process($a->id);
    expect(StudentTuitionPayment::query()->count())->toBe(0);
    $p->process($a->id);
    expect($a->fresh()->posted_at)->not->toBeNull()->and($c['account']->fresh()->paid_amount)->toBe(5_000000);

    $notification = $c['student']->user->notifications()->first();
    expect($notification)->not->toBeNull();
    /** @var array<string, mixed> $data */
    $data = $notification?->getAttribute('data') ?? [];
    expect($data['title'])->toBe('Tuition payment of 5.00 USDC sent')
        ->and($data['amount'])->toBe('5.00')
        ->and($data['tx_hash'])->toBe('0x'.str_repeat('f', 64))
        ->and($data['type'])->toBe('tuition_payment');
});

test('a transaction that exists but moved the wrong amount records nothing', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    tuitionReceiptStub($c, $a, 1_000000);
    $p = app(StudentTuitionPaymentProcessor::class);
    $p->process($a->id);
    $p->process($a->id);
    expect($a->fresh()->settlement_verdict)->toBe('mismatched')->and(StudentTuitionPayment::query()->count())->toBe(0);
});

test('a reverted receipt is a final failure, never a pending state', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    tuitionReceiptStub($c, $a, overrides: ['status' => '0x0']);
    $p = app(StudentTuitionPaymentProcessor::class);
    $p->process($a->id);
    $p->process($a->id);
    expect($a->fresh()->settlement_verdict)->toBe('reverted')->and(StudentTuitionPayment::query()->count())->toBe(0);
});

test('confirming the same payment twice credits tuition once', function (): void {
    $c = consentContext();
    $service = app(StudentTuitionPaymentService::class);
    $q = $service->quote($c['student'], $c['account'], 5_000000);
    $a = $service->pay($c['student'], $c['account'], 5_000000, $q['request_key'], $q['request_digest'], true);
    tuitionReceiptStub($c, $a);
    $p = app(StudentTuitionPaymentProcessor::class);
    $p->process($a->id);
    $p->process($a->id);
    $second = $service->pay($c['student'], $c['account']->fresh(), 5_000000, $q['request_key'], $q['request_digest'], true);
    $p->process($a->id);
    expect($second->id)->toBe($a->id)->and(StudentTuitionPayment::query()->count())->toBe(1)
        ->and($c['account']->fresh()->paid_amount)->toBe(5_000000);
});

test('a debt that shrinks after the payer confirmed lands the remainder as an overpayment', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    tuitionReceiptStub($c, $a);
    $p = app(StudentTuitionPaymentProcessor::class);
    $p->process($a->id);
    $c['account']->update(['total_amount' => 2_000000]);
    $p->process($a->id);
    $receipt = StudentTuitionPayment::query()->sole();
    expect($receipt->applied_base_units)->toBe(2_000000)->and($receipt->overpayment_base_units)->toBe(3_000000);

    $notification = $c['student']->user->notifications()->first();
    expect($notification)->not->toBeNull();
    /** @var array<string, mixed> $data */
    $data = $notification?->getAttribute('data') ?? [];
    expect($data['amount'])->toBe('5.00')
        ->and($data['applied_amount'])->toBe('2.00')
        ->and($data['overpayment_amount'])->toBe('3.00')
        ->and($data['message'])->toContain('overpayment credit');
});

test('a rehearsal without a payment provider records no debt and no ledger row', function (): void {
    $c = consentContext();
    config(['lepton.default' => 'fake']);
    $fake = new FakeLeptonGateway;
    $fake->seedBalance($c['student']->payout_address, 20_000000);
    app()->instance(ArcNetworkGateway::class, $fake);
    app()->instance(WalletGateway::class, $fake);
    $a = confirmConsent($c);
    expect($a->state)->toBe('simulated')->and($c['account']->fresh()->paid_amount)->toBe(0)
        ->and(StudentTuitionPayment::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(0);
});

test('an unreadable chain is an unresolved attempt, not a claim that no payment happened', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    mockExpectation($c['transport'], 'submit')->once()->andReturn(new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'));
    $c['transport']->shouldReceive('resolve')->andReturn(TransportResolution::completed('0x'.str_repeat('f', 64)));
    mockExpectation($c['arc'], 'rpc')->with('eth_getTransactionReceipt', Mockery::any())->andThrow(new RuntimeException('RPC timeout'));
    $p = app(StudentTuitionPaymentProcessor::class);
    $p->process($a->id);
    $p->process($a->id);
    expect($a->fresh()->settlement_verdict)->toBe('unreadable')->and(StudentTuitionPayment::query()->count())->toBe(0);
});

test('an unsubmitted held attempt does not reduce tuition effective remaining amount and auto-clears on new payment', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);

    // Simulate preflight block placing attempt into operator review
    $a->update([
        'stage' => 'needs_operator_review',
        'last_error' => 'Preflight blocked. Original institution and real Arc testnet rail required; no mainnet or fake fallback.',
        'leased_until' => null,
        'next_attempt_at' => null,
    ]);

    // Funds are immediately returned to the student tuition balance
    expect($c['account']->heldAmount())->toBe(0)
        ->and($c['account']->effectiveRemainingAmount())->toBe($c['account']->remainingAmount());

    // Paying again automatically clears the unsubmitted hold and does not throw unresolved payment exception
    $newAttempt = confirmConsent($c);
    expect($a->fresh()->state)->toBe('failed')
        ->and($a->fresh()->stage)->toBe('preflight_blocked')
        ->and($newAttempt->id)->not->toBe($a->id);
});

test('an unsubmitted expired attempt auto-clears on status poll', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);

    // Fast-forward beyond the 120-second consent window
    $this->travel(3)->minutes();

    $response = $this->actingAs($c['student']->user)
        ->getJson(route('student.tuition.attempts'));

    $response->assertOk();
    expect($a->fresh()->state)->toBe('failed')
        ->and($a->fresh()->stage)->toBe('quote_expired_unsubmitted');
});

test('a student can explicitly clear an unsubmitted hold', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);

    $a->update([
        'stage' => 'needs_operator_review',
        'last_error' => 'Preflight blocked.',
    ]);

    $response = $this->actingAs($c['student']->user)
        ->postJson(route('student.tuition.attempts.clear', $a));

    $response->assertOk();
    expect($a->fresh()->state)->toBe('failed')
        ->and($a->fresh()->stage)->toBe('quote_expired_unsubmitted');
});
