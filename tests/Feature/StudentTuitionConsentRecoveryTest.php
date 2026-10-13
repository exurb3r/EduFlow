<?php

declare(strict_types=1);

use App\Models\StudentTuitionPayment;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\StudentTuitionPaymentIntent;
use App\Models\Transaction;
use App\Services\Payments\AsyncPaymentTransport;
use App\Services\Payments\CircleAgentWalletTransport;
use App\Services\Payments\FeeGuarantee;
use App\Services\Payments\TransportAcceptance;
use App\Services\Payments\TransportResolution;
use App\Services\StudentTuitionPaymentProcessor;
use App\Services\StudentTuitionPaymentService;
use Brick\Math\BigInteger;
use Illuminate\Validation\ValidationException;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

test('quote and consent are immutable exact snapshots before any signer call', function (): void {
    $c = consentContext();
    $c['transport']->shouldNotReceive('submit');
    $a = confirmConsent($c);
    expect($a->hasValidConsent())->toBeTrue()->and($a->submission_started_at)->toBeNull()->and($a->fee_ceiling_base_units)->toBe(29452);
    expect(fn () => $a->update(['amount_base_units' => 1]))->toThrow(LogicException::class);
    expect(fn () => StudentTuitionPaymentIntent::query()->firstOrFail()->update(['snapshot_digest' => str_repeat('0', 64)]))->toThrow(LogicException::class);
});

test('acceptance records provider UUID separately and repeated jobs never resend', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    mockExpectation($c['transport'], 'submit')->once()->andReturn(new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'));
    $c['transport']->shouldReceive('resolve')->andReturn(TransportResolution::pending());
    $processor = app(StudentTuitionPaymentProcessor::class);
    $processor->process($a->id);
    $processor->process($a->id);
    expect($a->fresh()->state)->toBe('accepted')->and($a->fresh()->provider_reference)->toBeNull()
        ->and($a->fresh()->provider_handle)->not->toBeNull()->and(StudentTuitionPayment::query()->count())->toBe(0);
});

test('lost response becomes unknown and never submits twice', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    mockExpectation($c['transport'], 'submit')->once()->andThrow(new RuntimeException('timeout'));
    $processor = app(StudentTuitionPaymentProcessor::class);
    $processor->process($a->id);
    $processor->process($a->id);
    expect($a->fresh()->state)->toBe('unknown')->and($a->fresh()->submission_started_at)->not->toBeNull()
        ->and($a->fresh()->stage)->toBe('needs_operator_review');
});

test('crash after submission fence never reopens prepared state', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    $a->update(['state' => 'submitting', 'submission_started_at' => now()->subMinutes(10)]);
    $c['transport']->shouldNotReceive('submit');
    app(StudentTuitionPaymentProcessor::class)->process($a->id);
    expect($a->fresh()->state)->toBe('unknown');
});

test('matching receipt posts once from persisted proof and excludes fake ledger revenue', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    $hash = '0x'.str_repeat('f', 64);
    mockExpectation($c['transport'], 'submit')->once()->andReturn(new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'));
    $c['transport']->shouldReceive('resolve')->andReturn(TransportResolution::completed($hash));
    mockExpectation($c['arc'], 'rpc')->with('eth_getTransactionReceipt', [$hash])->andReturn([
        'transactionHash' => $hash, 'blockNumber' => '0x64', 'status' => '0x1', 'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1', 'logs' => []]);
    mockExpectation($c['arc'], 'rpc')->with('eth_getTransactionByHash', [$hash])->andReturn([
        'from' => $a->payer_address, 'to' => $a->recipient_address, 'value' => '0x'.BigInteger::of('5000000000000000000')->toBase(16), 'blockNumber' => '0x64']);
    mockExpectation($c['arc'], 'rpc')->with('eth_getBlockByNumber', ['0x64', false])->andReturn(['hash' => '0x'.str_repeat('a', 64)]);
    $processor = app(StudentTuitionPaymentProcessor::class);
    $processor->process($a->id);
    $processor->process($a->id);
    $processor->process($a->id);
    expect($a->fresh()->hasValidSettlement())->toBeTrue()->and($a->fresh()->posted_at)->not->toBeNull()
        ->and(StudentTuitionPayment::query()->count())->toBe(1)->and($c['account']->fresh()->paid_amount)->toBe(5_000000)
        ->and(Transaction::query()->count())->toBe(0);
});

test('confirmation rejects changed amount digest or expired quote', function (): void {
    $c = consentContext();
    $service = app(StudentTuitionPaymentService::class);
    $quote = $service->quote($c['student'], $c['account'], 5_000000);
    expect(fn () => $service->pay($c['student'], $c['account'], 1, $quote['request_key'], $quote['request_digest'], true))->toThrow(ValidationException::class);
    expect(fn () => $service->pay($c['student'], $c['account'], 5_000000, $quote['request_key'], str_repeat('0', 64), true))->toThrow(ValidationException::class);
    $this->travel(3)->minutes();
    expect(fn () => $service->pay($c['student'], $c['account'], 5_000000, $quote['request_key'], $quote['request_digest'], true))->toThrow(ValidationException::class);
});

test('unknown attempt blocks different request key replacement', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    $a->update(['state' => 'unknown', 'submission_started_at' => now()]);
    expect(fn (): StudentTuitionPaymentAttempt => confirmConsent($c))->toThrow(ValidationException::class, 'unresolved payment');
});

test('the stock Circle rail reports an honest, weaker fee guarantee', function (): void {
    $transport = new CircleAgentWalletTransport('/nonexistent/binary');

    // The installed CLI sets no fee ceiling, so it must never claim to. What is
    // true instead -- measured on real relayed receipts -- is that the rail
    // sponsors the gas and the payer loses exactly the principal.
    expect($transport->feeGuarantee())->toBe(FeeGuarantee::RelayerSponsored)
        ->and($transport->supportsFeeCeiling())->toBeFalse();
});

test('a rail with no fee bound at all is refused before the signer', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    $transport = tuitionTransport(FeeGuarantee::Unbounded);
    $transport->shouldNotReceive('submit');
    app()->instance(AsyncPaymentTransport::class, $transport);

    app(StudentTuitionPaymentProcessor::class)->process($a->id);

    expect($a->fresh()->state)->toBe('prepared')->and($a->fresh()->stage)->toBe('needs_operator_review')
        ->and($a->fresh()->submission_started_at)->toBeNull();
});

test('a sponsored rail lets a wallet holding far more than the total proceed', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    // The fixture funds 20 USDC against a 5 USDC principal. An earlier rule
    // required the balance to be no greater than principal + ceiling, which made
    // every normally funded wallet unable to send anything on this rail -- the
    // check was bounding an account that pays no gas.
    $transport = tuitionTransport(FeeGuarantee::RelayerSponsored);
    mockExpectation($transport, 'submit')->once()->andReturn(new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'));
    mockExpectation($transport, 'resolve')->andReturn(TransportResolution::pending());
    app()->instance(AsyncPaymentTransport::class, $transport);

    app(StudentTuitionPaymentProcessor::class)->process($a->id);

    // The guarantee is recorded, so a later reader knows the rail paid the fee
    // rather than the transaction capping it.
    expect($a->fresh()->state)->toBe('accepted')
        ->and($a->fresh()->fee_guarantee)->toBe(FeeGuarantee::RelayerSponsored->value);
});

test('a sponsored rail refuses a wallet that cannot cover the principal itself', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    // 4.999999 USDC against a 5.000000 principal: the rail's sponsorship does not
    // make the principal affordable.
    app()->instance(ArcNetworkGateway::class, tuitionArcGateway(4_999_999));
    $transport = tuitionTransport(FeeGuarantee::RelayerSponsored);
    $transport->shouldNotReceive('submit');
    app()->instance(AsyncPaymentTransport::class, $transport);

    app(StudentTuitionPaymentProcessor::class)->process($a->id);

    expect($a->fresh()->state)->toBe('prepared')->and($a->fresh()->submission_started_at)->toBeNull()
        ->and($a->fresh()->last_error)->toContain('cannot cover the amount this rail will debit');
});

test('a wallet holding exactly the principal may pay it on a sponsored rail', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    app()->instance(ArcNetworkGateway::class, tuitionArcGateway(5_000_000));
    $transport = tuitionTransport(FeeGuarantee::RelayerSponsored);
    mockExpectation($transport, 'submit')->once()->andReturn(new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'));
    mockExpectation($transport, 'resolve')->andReturn(TransportResolution::pending());
    app()->instance(AsyncPaymentTransport::class, $transport);

    app(StudentTuitionPaymentProcessor::class)->process($a->id);

    expect($a->fresh()->state)->toBe('accepted');
});

test('a signer-enforced rail records the strong guarantee', function (): void {
    $c = consentContext();
    $a = confirmConsent($c);
    mockExpectation($c['transport'], 'submit')->once()->andReturn(new TransportAcceptance('116c7ce7-8f2f-53ac-8b06-f18aac151ce3'));
    mockExpectation($c['transport'], 'resolve')->andReturn(TransportResolution::pending());

    app(StudentTuitionPaymentProcessor::class)->process($a->id);

    expect($a->fresh()->fee_guarantee)->toBe(FeeGuarantee::SignerEnforced->value);
});
