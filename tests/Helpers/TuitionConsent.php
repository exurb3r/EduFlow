<?php

declare(strict_types=1);

use App\Models\AcademicTerm;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\StudentWallet;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Services\Payments\AsyncPaymentTransport;
use App\Services\Payments\FeeBoundedPaymentTransport;
use App\Services\Payments\FeeGuarantee;
use App\Services\StudentTuitionPaymentService;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\AuthGateway;

/**
 * Shared fixtures for the tuition consent, recovery and intake suites.
 *
 * These build a real student, wallet, tuition account, rail, Arc gateway and
 * authenticated operator session so the tests exercise the production wiring
 * rather than a stub of it. Only the rail and the chain are scripted.
 */

/**
 * A consenting pilot student with an active operator-generated wallet, a funded
 * Arc testnet rail and a named finance recovery owner.
 *
 * @return array{student: Student, account: TuitionAccount, transport: FeeBoundedPaymentTransport&MockInterface, arc: ArcNetworkGateway&MockInterface}
 */
function consentContext(): array
{
    Bus::fake();
    config(['eduflow.institution_id' => null, 'lepton.default' => 'circle', 'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.chain_id' => 5042002, 'lepton.arc.treasury' => '0x'.str_repeat('2', 40),
        'eduflow.student_tuition.enabled' => true, 'eduflow.student_tuition.observe_enabled' => true,
        'eduflow.student_tuition.queue_connection' => 'database', 'queue.connections.database.retry_after' => 360,
        'eduflow.student_tuition.auto_intake' => false]);
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
    // byDefault: a test that needs a different funded balance (for example to
    // pin the sender-loss bound) must be able to replace this rather than have
    // its own expectation shadowed by it.
    mockExpectation($arc, 'rpc')->with('eth_getBalance', Mockery::any())
        ->andReturn('0x'.BigInteger::of('20000000000000000000')->toBase(16))->byDefault();
    $auth = Mockery::mock(AuthGateway::class);
    mockExpectation($auth, 'isAuthenticatedFor')->with('ARC-TESTNET')->andReturn(true);
    app()->instance(AuthGateway::class, $auth);
    app()->instance(ArcNetworkGateway::class, $arc);
    $transport = Mockery::mock(FeeBoundedPaymentTransport::class);
    if (! $transport instanceof FeeBoundedPaymentTransport) {
        throw new RuntimeException('Transport mock does not implement fee-bounded rail.');
    }
    // byDefault so an individual test can state a different rail guarantee
    // without fighting a competing expectation from this fixture.
    $transport->shouldReceive('feeGuarantee')->andReturn(FeeGuarantee::SignerEnforced)->byDefault();
    $transport->shouldReceive('supportsFeeCeiling')->andReturn(true)->byDefault();
    $transport->shouldReceive('estimate')->andReturn(['networkFee' => '0.02945113436514256']);
    app()->instance(AsyncPaymentTransport::class, $transport);

    return ['student' => $student, 'account' => $account, 'arc' => $arc, 'transport' => $transport];
}

/**
 * Take a quote and accept it, producing the one attempt every later step reuses.
 *
 * @param  array{student: Student, account: TuitionAccount, transport: FeeBoundedPaymentTransport&MockInterface, arc: ArcNetworkGateway&MockInterface}  $c
 */
function confirmConsent(array $c): StudentTuitionPaymentAttempt
{
    $service = app(StudentTuitionPaymentService::class);
    $quote = $service->quote($c['student'], $c['account'], 5_000000);

    return $service->pay($c['student'], $c['account'], 5_000000, $quote['request_key'], $quote['request_digest'], true);
}
