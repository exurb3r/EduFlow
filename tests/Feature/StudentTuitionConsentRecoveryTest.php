<?php

declare(strict_types=1);

use App\Models\AcademicTerm;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\StudentTuitionPaymentIntent;
use App\Models\StudentWallet;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Services\Payments\AsyncPaymentTransport;
use App\Services\Payments\CircleAgentWalletTransport;
use App\Services\Payments\FeeBoundedPaymentTransport;
use App\Services\Payments\TransportAcceptance;
use App\Services\Payments\TransportResolution;
use App\Services\StudentTuitionPaymentProcessor;
use App\Services\StudentTuitionPaymentService;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\AuthGateway;

/** @return array{student:Student,account:TuitionAccount,transport:FeeBoundedPaymentTransport&MockInterface,arc:ArcNetworkGateway&MockInterface} */
function consentContext(): array
{
    Bus::fake();
    config(['eduflow.institution_id' => null, 'lepton.default' => 'circle', 'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.chain_id' => 5042002, 'lepton.arc.treasury' => '0x'.str_repeat('2', 40),
        'eduflow.student_tuition.enabled' => true, 'eduflow.student_tuition.observe_enabled' => true,
        'eduflow.student_tuition.queue_connection' => 'database', 'queue.connections.database.retry_after' => 360]);
    Organization::factory()->create();
    $student = Student::factory()->create(['payout_address' => '0x'.str_repeat('1', 40)]);
    if (! $student instanceof Student) {
        throw new RuntimeException('Student factory returned no student.');
    }
    $student->user->assignRole(Role::findOrCreate('student', 'web'));
    $owner = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    config(['eduflow.student_tuition.investigation_owner_id' => $owner->id]);
    StudentWallet::factory()->create(['student_id' => $student->id, 'address' => $student->payout_address, 'provider' => 'circle', 'status' => 'active', 'chain' => 'ARC-TESTNET']);
    $account = TuitionAccount::factory()->create(['student_id' => $student->id, 'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 100_000000, 'paid_amount' => 0]);
    if (! $account instanceof TuitionAccount) {
        throw new RuntimeException('Tuition factory returned no account.');
    }
    config(['eduflow.student_tuition.pilot_account_ids' => [$account->id]]);
    $arc = Mockery::mock(ArcNetworkGateway::class);
    if (! $arc instanceof ArcNetworkGateway) {
        throw new RuntimeException('Arc mock does not implement gateway.');
    }
    $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
    $arc->shouldReceive('chainId')->andReturn(5042002);
    mockExpectation($arc, 'addressExplorerUrl')->andReturn('https://example.test/address');
    mockExpectation($arc, 'explorerUrl')->andReturn('https://example.test/tx');
    mockExpectation($arc, 'rpc')->with('eth_chainId')->andReturn('0x4cef52');
    mockExpectation($arc, 'rpc')->with('eth_getBalance', Mockery::any())->andReturn('0x'.BigInteger::of('20000000000000000000')->toBase(16));
    $auth = Mockery::mock(AuthGateway::class);
    mockExpectation($auth, 'isAuthenticatedFor')->with('ARC-TESTNET')->andReturn(true);
    app()->instance(AuthGateway::class, $auth);
    app()->instance(ArcNetworkGateway::class, $arc);
    $transport = Mockery::mock(FeeBoundedPaymentTransport::class);
    if (! $transport instanceof FeeBoundedPaymentTransport) {
        throw new RuntimeException('Transport mock does not implement fee-bounded rail.');
    }
    $transport->shouldReceive('supportsFeeCeiling')->andReturn(true);
    $transport->shouldReceive('estimate')->andReturn(['networkFee' => '0.02945113436514256']);
    app()->instance(AsyncPaymentTransport::class, $transport);

    return ['student' => $student, 'account' => $account, 'arc' => $arc, 'transport' => $transport];
}

/** @param array{student:Student,account:TuitionAccount,transport:FeeBoundedPaymentTransport&MockInterface,arc:ArcNetworkGateway&MockInterface} $c */
function confirmConsent(array $c): StudentTuitionPaymentAttempt
{
    $service = app(StudentTuitionPaymentService::class);
    $quote = $service->quote($c['student'], $c['account'], 5_000000);

    return $service->pay($c['student'], $c['account'], 5_000000, $quote['request_key'], $quote['request_digest'], true);
}

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

test('stock Circle transport cannot sign a fee bounded tuition request', function (): void {
    $transport = new CircleAgentWalletTransport('/nonexistent/binary');
    expect($transport->supportsFeeCeiling())->toBeFalse()
        ->and(fn (): TransportAcceptance => $transport->submit(['from' => '0x'.str_repeat('1', 40), 'to' => '0x'.str_repeat('2', 40),
            'amount_base_units' => 1, 'chain' => 'ARC-TESTNET', 'idempotency_key' => (string) Str::uuid(), 'max_fee_base_units' => 1]))
        ->toThrow(RuntimeException::class, 'before invoking');
});
