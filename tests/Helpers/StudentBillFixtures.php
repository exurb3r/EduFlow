<?php

declare(strict_types=1);

use App\Ai\Advisory\AdvisoryEnvelope;
use App\Ai\Advisory\AdvisoryGate;
use App\Enums\StudentBillCategory;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\StudentBillPayment;
use App\Models\StudentWallet;
use App\Models\User;
use App\Services\Payments\AsyncPaymentTransport;
use App\Services\Payments\FeeBoundedPaymentTransport;
use App\Services\Payments\FeeGuarantee;
use App\Services\Payments\TransportAcceptance;
use App\Services\StudentBillPaymentQuote;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\AuthGateway;

/**
 * Shared fixtures for the student-bill quote, allocation and submission suites.
 *
 * A student, an active operator-generated wallet, one bill, a funded Arc testnet
 * rail and a scripted chain. Only the rail and the chain are mocked: the quote,
 * the consent, the policy assessment, the settlement verification, the allocation
 * and the submission worker are all production code, so what these suites assert
 * is the lane's own behaviour and not a stub of it.
 */
function billPaymentContext(array $overrides = [], ?Organization $institution = null): array
{
    Bus::fake();
    config(['eduflow.institution_id' => null, 'lepton.default' => 'circle', 'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.chain_id' => 5042002, 'lepton.arc.treasury' => '0x'.str_repeat('2', 40)]);
    if (! $institution instanceof Organization) {
        Organization::factory()->create();
    }

    $student = Student::factory()->create(['payout_address' => '0x'.str_repeat('1', 40)]);
    if (! $student instanceof Student) {
        throw new RuntimeException('Student factory returned no student.');
    }
    StudentWallet::factory()->create([
        'student_id' => $student->id, 'address' => $student->payout_address,
        'provider' => 'circle', 'status' => 'active', 'chain' => 'ARC-TESTNET',
    ]);
    $bill = StudentBill::factory()->create([
        'student_id' => $student->id,
        'category' => $overrides['category'] ?? StudentBillCategory::LABORATORY,
        'amount_base_units' => $overrides['amount_base_units'] ?? 150_000_000,
        'paid_base_units' => 0,
        'status' => StudentBill::STATUS_OPEN,
    ]);
    if (! $bill instanceof StudentBill) {
        throw new RuntimeException('Student bill factory returned no bill.');
    }

    $balance = BigInteger::of($overrides['balance'] ?? 20_000_000)->multipliedBy('1000000000000');
    $arc = Mockery::mock(ArcNetworkGateway::class);
    if (! $arc instanceof ArcNetworkGateway) {
        throw new RuntimeException('Arc mock does not implement gateway.');
    }
    $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
    $arc->shouldReceive('chainId')->andReturn(5042002);
    mockExpectation($arc, 'addressExplorerUrl')->andReturn('https://example.test/address');
    mockExpectation($arc, 'explorerUrl')->andReturn('https://example.test/tx');
    mockExpectation($arc, 'rpc')->with('eth_chainId')->andReturn('0x4cef52');
    mockExpectation($arc, 'rpc')->with('eth_getBalance', Mockery::any())->andReturn('0x'.$balance->toBase(16));
    app()->instance(ArcNetworkGateway::class, $arc);

    $transport = Mockery::mock(AsyncPaymentTransport::class);
    if (! $transport instanceof AsyncPaymentTransport) {
        throw new RuntimeException('Transport mock does not implement the rail.');
    }
    $transport->shouldReceive('estimate')->andReturn(['networkFee' => '0.02945113436514256']);
    app()->instance(AsyncPaymentTransport::class, $transport);

    $auth = Mockery::mock(AuthGateway::class);
    if (! $auth instanceof AuthGateway) {
        throw new RuntimeException('Auth mock does not implement gateway.');
    }
    mockExpectation($auth, 'isAuthenticatedFor')->with('ARC-TESTNET')->andReturn(true);
    app()->instance(AuthGateway::class, $auth);

    return ['student' => $student, 'bill' => $bill, 'arc' => $arc, 'auth' => $auth];
}

/**
 * A sanitized advisory classification, or none at all.
 *
 * Built through the gate's sanitizer rather than by asking a model: the sanitizer
 * is the only place model output becomes application state, and `null` is the
 * honest answer when the switches are off — which is the real-world default, and
 * the branch that escalates.
 *
 * @param  array<string, mixed>  $overrides
 */
function billAdvisory(array $overrides = []): ?AdvisoryEnvelope
{
    return app(AdvisoryGate::class)->sanitizeClassification(array_merge([
        'category' => 'laboratory',
        'beneficiary_alias' => 'Chemistry Department',
        'confidence' => 0.97,
        'narrative' => 'Consumables for a laboratory subject this term.',
        'anomaly_flags' => [],
    ], $overrides));
}

/**
 * Mount a quote and record the payer's acceptance of it.
 *
 * @param  array<string, mixed>  $c
 * @param  array<string, mixed>  $accept
 */
function billConsent(array $c, int $amount = 5_000_000, ?AdvisoryEnvelope $advisory = null, array $accept = []): StudentBillPayment
{
    /** @var StudentBillPaymentQuote $service */
    $service = app(StudentBillPaymentQuote::class);
    $quote = $service->quote($c['student'], $c['bill'], $amount, null, $advisory);

    return $service->consent(
        $c['student'],
        $c['bill'],
        $amount,
        $quote['request_key'],
        $accept['digest'] ?? $quote['request_digest'],
        $accept['accepted'] ?? true,
    );
}

/**
 * Script the chain's answer for one transfer.
 *
 * The `$marker` fills the hash: a chain cannot contain the same hash twice, so
 * two genuinely different payments need two different ones, while the
 * double-posting case deliberately reuses one.
 *
 * @param  array<string, mixed>  $c
 * @param  array<string, mixed>  $overrides
 */
function billSettlementStub(array $c, StudentBillPayment $payment, int $amount = 5_000_000, array $overrides = [], string $marker = 'f'): string
{
    $hash = '0x'.str_repeat($marker, 64);
    mockExpectation($c['arc'], 'rpc')->with('eth_getTransactionReceipt', [$hash])->andReturn([
        'transactionHash' => $hash, 'blockNumber' => '0x64', 'status' => '0x1',
        'gasUsed' => '0x5208', 'effectiveGasPrice' => '0x1', 'logs' => [], ...$overrides,
    ]);
    mockExpectation($c['arc'], 'rpc')->with('eth_getTransactionByHash', [$hash])->andReturn([
        'from' => $payment->payer_address, 'to' => $payment->recipient_address,
        'value' => '0x'.BigInteger::of($amount)->multipliedBy('1000000000000')->toBase(16), 'blockNumber' => '0x64',
    ]);
    mockExpectation($c['arc'], 'rpc')->with('eth_getBlockByNumber', ['0x64', false])->andReturn(['hash' => '0x'.str_repeat('a', 64)]);

    return $hash;
}

/**
 * Quote, consent, prove the movement and allocate it. The whole verified path.
 *
 * @param  array<string, mixed>  $c
 */

/**
 * Quote, consent, prove the movement and allocate it. The whole verified path.
 *
 * @param  array<string, mixed>  $c
 */
function billSettledPayment(array $c, int $amount = 5_000_000, ?AdvisoryEnvelope $advisory = null): StudentBillPayment
{
    /** @var StudentBillPaymentQuote $service */
    $service = app(StudentBillPaymentQuote::class);
    $payment = billConsent($c, $amount, $advisory);
    $service->recordSettlement($payment, billSettlementStub($c, $payment));

    return $service->allocate($payment->fresh());
}

/* ------------------------------------------------------------------ *
 * Submission-lane switches and rails, shared with the processor suite.
 * ------------------------------------------------------------------ */

/** Enable the lane with a proper recovery owner and an explicit pilot allow-list. */
function enableBillLane(?User $owner = null): User
{
    $owner ??= User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    config([
        'eduflow.student_bills.enabled' => true,
        'eduflow.student_bills.observe_enabled' => true,
        'eduflow.student_bills.stop_switch' => false,
        'eduflow.student_bills.recovery_owner_id' => $owner->id,
        'eduflow.student_bills.queue_connection' => 'database',
        'queue.connections.database.retry_after' => 360,
    ]);

    return $owner;
}

/** @param  array<string, mixed>  $c */
function allowPilot(array $c): void
{
    config(['eduflow.student_bills.pilot_bill_ids' => [$c['bill']->id]]);
}

/**
 * Script a rail that accepts one transfer and answers observation with `$resolve`.
 *
 * The resolver is a parameter rather than something the caller adds to a returned
 * mock: Mockery's generated class for an interface cannot satisfy a
 * `MockInterface` return type, and re-resolving it through the container loses the
 * mock type again. Keeping the rail's wiring in one place is the point of having
 * a helper at all.
 *
 * @param  callable|null  $resolve  answers an observation; omit when the test never observes
 */
function acceptingRail(?callable $resolve = null, string $handle = '116c7ce7-8f2f-53ac-8b06-f18aac151ce3'): void
{
    $transport = Mockery::mock(FeeBoundedPaymentTransport::class);
    if (! $transport instanceof FeeBoundedPaymentTransport) {
        throw new RuntimeException('Rail mock does not implement the fee capability.');
    }
    $transport->shouldReceive('feeGuarantee')->andReturn(FeeGuarantee::RelayerSponsored);
    $transport->shouldReceive('supportsFeeCeiling')->andReturn(false);
    $transport->shouldReceive('estimate')->andReturn(['networkFee' => '0.02926511166025']);
    mockExpectation($transport, 'submit')->andReturn(new TransportAcceptance($handle));
    if ($resolve !== null) {
        mockExpectation($transport, 'resolve')->andReturnUsing($resolve);
    }
    app()->instance(AsyncPaymentTransport::class, $transport);
}

/**
 * A rail that can price a transfer but must never be asked to send one.
 *
 * Used by every refusal case: the point is that preflight rejects before the
 * signer, so `submit` carrying a `shouldNotReceive` expectation is the assertion.
 */
function quietRail(FeeGuarantee $guarantee = FeeGuarantee::RelayerSponsored): void
{
    $transport = Mockery::mock(FeeBoundedPaymentTransport::class);
    if (! $transport instanceof FeeBoundedPaymentTransport) {
        throw new RuntimeException('Rail mock does not implement the fee capability.');
    }
    $transport->shouldReceive('feeGuarantee')->andReturn($guarantee);
    $transport->shouldReceive('supportsFeeCeiling')->andReturn($guarantee === FeeGuarantee::SignerEnforced);
    $transport->shouldReceive('estimate')->andReturn(['networkFee' => '0.02926511166025']);
    $transport->shouldNotReceive('submit');
    app()->instance(AsyncPaymentTransport::class, $transport);
}

/**
 * A scripted Arc gateway at an exact funded balance.
 *
 * `billPaymentContext()` binds one for the fixture, but a test that needs the
 * balance to *change* — a wallet that could pay at consent and cannot by the time
 * the worker runs — needs a different reading without rebuilding the student.
 */
function billArcGateway(int $balanceBaseUnits): ArcNetworkGateway
{
    $arc = Mockery::mock(ArcNetworkGateway::class);
    if (! $arc instanceof ArcNetworkGateway) {
        throw new RuntimeException('Arc mock does not implement gateway.');
    }
    $arc->shouldReceive('chainCode')->andReturn('ARC-TESTNET');
    $arc->shouldReceive('chainId')->andReturn(5042002);
    mockExpectation($arc, 'addressExplorerUrl')->andReturn('https://example.test/address');
    mockExpectation($arc, 'explorerUrl')->andReturn('https://example.test/tx');
    mockExpectation($arc, 'rpc')->with('eth_chainId')->andReturn('0x4cef52');
    mockExpectation($arc, 'rpc')->with('eth_getBalance', Mockery::any())
        ->andReturn('0x'.BigInteger::of($balanceBaseUnits)->multipliedBy('1000000000000')->toBase(16));
    app()->instance(ArcNetworkGateway::class, $arc);

    return $arc;
}

/**
 * A rail that prices a transfer and then does exactly one thing to it.
 *
 * The submit behaviour is a parameter because "the rail refuses" and "the rail
 * times out" are the two outcomes the no-double-send property exists for, and both
 * need the same pricing wiring. Mocking this interface inline in each test
 * produced a class that did not satisfy the transport contract, so it is built
 * here once.
 */
function sendingRail(callable $submit, FeeGuarantee $guarantee = FeeGuarantee::RelayerSponsored): void
{
    $rail = Mockery::mock(FeeBoundedPaymentTransport::class);
    if (! $rail instanceof FeeBoundedPaymentTransport) {
        throw new RuntimeException('Rail mock does not implement the fee capability.');
    }
    $rail->shouldReceive('feeGuarantee')->andReturn($guarantee);
    $rail->shouldReceive('supportsFeeCeiling')->andReturn($guarantee === FeeGuarantee::SignerEnforced);
    $rail->shouldReceive('estimate')->andReturn(['networkFee' => '0.02926511166025']);
    mockExpectation($rail, 'submit')->andReturnUsing($submit);
    app()->instance(AsyncPaymentTransport::class, $rail);
}
