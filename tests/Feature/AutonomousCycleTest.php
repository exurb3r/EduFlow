<?php

declare(strict_types=1);

use App\Agents\EduFlowAgent;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\CircleWalletService;
use App\Services\InstallationInstitution;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\Expectation;
use Mockery\MockInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonCliException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/**
 * @return array{institution: Organization, wallet: Wallet}
 */
function autonomousCycleTestContext(): array
{
    app()->forgetInstance(InstallationInstitution::class);

    Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::query()->firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

    /** @var Organization $institution */
    $institution = Organization::query()->firstOrCreate(
        ['name' => 'Autonomous Cycle Academy'],
        [
            'currency' => 'USDC',
            'minimum_reserve' => 10000.00,
            'max_auto_payment' => 1000.00,
            'max_daily_disbursement' => 5000.00,
            'human_approval_threshold' => 1000.00,
        ]
    );

    /** @var Wallet $wallet */
    $wallet = Wallet::query()->firstOrCreate(
        ['organization_id' => $institution->id],
        [
            'provider' => 'circle',
            'network' => 'arc',
            'address' => '0xautonomouscyclewallet',
            'balance' => 25420.00,
            'status' => 'active',
        ]
    );

    config(['eduflow.institution_id' => $institution->id, 'lepton.default' => 'fake']);

    return ['institution' => $institution, 'wallet' => $wallet];
}

beforeEach(function (): void {
    autonomousCycleTestContext();
});

test('unauthenticated visitors cannot trigger autonomous cycle', function (): void {
    $response = postJson(route('finance.autonomous-cycle.store'), ['confirmed' => true]);

    $response->assertUnauthorized();
});

test('regular users without administrative roles are forbidden', function (): void {
    $user = User::factory()->create()->assignRole('user');

    $response = actingAs($user)->postJson(route('finance.autonomous-cycle.store'), ['confirmed' => true]);

    $response->assertForbidden();
});

test('confirmation is required to run autonomous cycle', function (): void {
    $admin = User::factory()->create()->assignRole('admin');

    $response = actingAs($admin)->postJson(route('finance.autonomous-cycle.store'), []);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['confirmed']);
});

test('client cannot override institution or payment destinations', function (string $field): void {
    $admin = User::factory()->create()->assignRole('super_admin');

    $response = actingAs($admin)->postJson(route('finance.autonomous-cycle.store'), [
        'confirmed' => true,
        $field => 'arbitrary-value',
    ]);

    $response->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with(['organization_id', 'amount', 'recipient_address']);

test('autonomous cycle returns unprocessable when institution context is unavailable', function (): void {
    $admin = User::factory()->create()->assignRole('super_admin');
    $institution = Organization::query()->sole();
    config(['eduflow.institution_id' => $institution->id + 9999]);

    $response = actingAs($admin)->postJson(route('finance.autonomous-cycle.store'), ['confirmed' => true]);

    $response->assertUnprocessable()
        ->assertSee('Institution context unavailable');
});

test('autonomous cycle returns unprocessable when institution primary wallet is missing', function (): void {
    $admin = User::factory()->create()->assignRole('super_admin');
    $institution = Organization::query()->sole();
    $wallet = $institution->primaryWallet();
    expect($wallet)->not->toBeNull();
    $wallet->delete();

    $response = actingAs($admin)->postJson(route('finance.autonomous-cycle.store'), ['confirmed' => true]);

    $response->assertUnprocessable()
        ->assertSee('Active institution wallet unavailable');
});

test('concurrency guard prevents overlapping runs for the same institution', function (): void {
    $admin = User::factory()->create()->assignRole('super_admin');
    $institution = Organization::query()->sole();
    /** @var LockProvider $cacheStore */
    $cacheStore = Cache::store('database');
    $lock = $cacheStore->lock('eduflow:autonomous-cycle:'.$institution->id, 86400);
    expect($lock->get())->toBeTrue();

    try {
        $response = actingAs($admin)->postJson(route('finance.autonomous-cycle.store'), ['confirmed' => true]);
        $response->assertStatus(409)
            ->assertSee('Another institution cycle is running or requires review after interruption.');
    } finally {
        $lock->release();
    }
});

test('autonomous cycle streams ordered public SSE events, disclaims settlement, and releases lock', function (string $role): void {
    $institution = Organization::query()->sole();
    $admin = User::factory()->create()->assignRole($role);
    /** @var MockInterface&EduFlowAgent $agent */
    $agent = Mockery::mock(EduFlowAgent::class);

    /** @var Expectation $runCycle */
    $runCycle = $agent->shouldReceive('runAutonomousCycle');
    $runCycle->once()
        ->with(
            Mockery::on(fn (Organization $org): bool => $org->is($institution)),
            Mockery::type(Closure::class)
        )
        ->andReturnUsing(function (Organization $org, Closure $onProgress): array {
            $onProgress([
                'phase' => 'forecast',
                'title' => 'Starting liquidity forecast',
                'summary' => 'Computing 30-day liquidity from the local ledger.',
            ]);
            $onProgress([
                'phase' => 'policy',
                'title' => 'Invoice policy result recorded',
                'summary' => 'Auto-approved within thresholds.',
                'decision_id' => 12,
                'policy' => 'INVOICE_AUTO_V1',
                'status' => 'auto_approve',
                'reference' => 'INV-TEST-SSE',
            ]);

            return [
                'stats' => [
                    'auto_paid' => 1,
                    'escalated' => 0,
                    'held' => 0,
                    'rejected' => 0,
                    'total_disbursed_usdc' => 450.00,
                ],
            ];
        });

    app()->instance(EduFlowAgent::class, $agent);

    $response = actingAs($admin)->post(route('finance.autonomous-cycle.store'), ['confirmed' => true], [
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ]);

    $response->assertSuccessful();
    expect($response->baseResponse->headers->get('Content-Type'))->toContain('text/event-stream')
        ->and($response->baseResponse->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->baseResponse->headers->get('X-Accel-Buffering'))->toBe('no');

    $content = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n\n", trim($content)), fn (string $block): bool => str_starts_with($block, 'data: ')));
    expect($lines)->toHaveCount(4);

    $events = array_map(fn (string $block): array => json_decode(substr($block, 6), true, 512, JSON_THROW_ON_ERROR), $lines);

    $runId = $events[0]['run_id'];
    expect(Str::isUuid($runId))->toBeTrue();

    // Verify monotonically increasing sequences and frame schemas
    foreach ($events as $index => $event) {
        expect($event['sequence'])->toBe($index)
            ->and($event['run_id'])->toBe($runId)
            ->and(isset($event['occurred_at']))->toBeTrue();
    }

    expect($events[0])->toMatchArray([
        'type' => 'cycle_started',
        'phase' => 'start',
        'title' => 'Autonomous cycle started',
    ]);

    expect($events[1])->toMatchArray([
        'type' => 'cycle_progress',
        'phase' => 'forecast',
        'title' => 'Starting liquidity forecast',
    ]);

    expect($events[2])->toMatchArray([
        'type' => 'cycle_progress',
        'phase' => 'policy',
        'title' => 'Invoice policy result recorded',
        'decision_id' => 12,
        'policy' => 'INVOICE_AUTO_V1',
        'reference' => 'INV-TEST-SSE',
        'status' => 'auto_approve',
    ]);

    expect($events[3])->toMatchArray([
        'type' => 'cycle_completed',
        'phase' => 'complete',
        'title' => 'Cycle completed',
        'stats' => [
            'auto_paid' => 1,
            'escalated' => 0,
            'held' => 0,
            'rejected' => 0,
            'total_disbursed_usdc' => '450.000000',
        ],
    ])->and($events[3]['summary'])->toContain('not verified on-chain settlement');

    // Verify lock is released and can be acquired immediately
    /** @var LockProvider $cacheStore */
    $cacheStore = Cache::store('database');
    $testLock = $cacheStore->lock('eduflow:autonomous-cycle:'.$institution->id, 10);
    expect($testLock->get())->toBeTrue();
    $testLock->release();
})->with(['admin', 'super_admin']);

test('cycle failures stream safe events and redact secrets across failure types', function (string $failure): void {
    $institution = Organization::query()->sole();
    $wallet = $institution->primaryWallet();
    expect($wallet)->not->toBeNull();
    $admin = User::factory()->create()->assignRole('super_admin');
    actingAs($admin);
    Http::preventStrayRequests();
    Exceptions::fake();
    $logs = new TestHandler;
    Log::swap(new Logger('cycle-failure-endpoint-regression', [$logs]));

    foreach ([WalletGateway::class, ArcNetworkGateway::class] as $gateway) {
        app()->instance($gateway, Mockery::mock($gateway));
    }

    $payments = Mockery::mock(CircleWalletService::class);
    $payments->shouldNotReceive('executePayment');
    $payments->shouldNotReceive('executePaymentBaseUnits');
    app()->instance(CircleWalletService::class, $payments);

    $vendor = Vendor::query()->create([
        'organization_id' => $institution->id,
        'name' => 'Endpoint Cycle Failure Vendor',
        'wallet_address' => '0x'.str_repeat('4', 40),
        'status' => 'verified',
        'risk_level' => 'low',
    ]);
    $invoice = Invoice::query()->create([
        'organization_id' => $institution->id,
        'vendor_id' => $vendor->id,
        'reference' => 'INV-ENDPOINT-FAILURE',
        'amount' => 10.00,
        'due_date' => now()->addDay(),
        'status' => 'pending',
    ]);
    $transaction = Transaction::factory()->create([
        'organization_id' => $institution->id,
        'wallet_id' => $wallet->id,
        'type' => 'vendor_payment',
        'amount' => 3.00,
        'metadata' => ['is_fake' => true],
    ]);
    $walletBefore = $wallet->fresh()->getAttributes();
    $invoiceBefore = $invoice->fresh()->getAttributes();
    $transactionBefore = $transaction->fresh()->getAttributes();

    $insufficientFundsError = 'Error: Service returned error 400: the asset amount owned by the wallet is insufficient for the transaction.';
    $command = [
        'circle', 'wallet', 'transfer', '0x'.str_repeat('3', 40),
        '--amount', '10', '--address', $vendor->wallet_address,
        '--chain', 'ARC-TESTNET', '--rpc-url', 'https://rpc.example.invalid/v1/test-rpc-secret',
        '--idempotency-key', 'test-idempotency-key', '--output', 'json',
    ];
    $untrustedError = '<script>alert("test-secret")</script> https://rpc.example.invalid/v1/test-rpc-secret';

    expect(class_exists(CliRunner::class))->toBeTrue();
    $exception = match ($failure) {
        'insufficient_funds' => new LeptonCliException($command, 1, $insufficientFundsError),
        'cli_failure' => new LeptonCliException($command, 1, 'Error: Service returned error 503: '.$untrustedError),
        'timeout' => new RuntimeException('Process timed out: '.implode(' ', $command), previous: new RuntimeException($untrustedError)),
        'unrelated_insufficient_funds' => new RuntimeException($insufficientFundsError.' '.$untrustedError),
        'argument_lookalike' => new LeptonCliException([...$command, $insufficientFundsError], 1, $untrustedError),
        default => throw new InvalidArgumentException('Unknown cycle failure scenario.'),
    };

    /** @var MockInterface&EduFlowAgent $agent */
    $agent = Mockery::mock(EduFlowAgent::class);
    /** @var Expectation $runCycle */
    $runCycle = $agent->shouldReceive('runAutonomousCycle');
    $runCycle->once()
        ->with(
            Mockery::on(fn (Organization $organization): bool => $organization->is($institution)),
            Mockery::type(Closure::class)
        )
        ->andThrow($exception);
    app()->instance(EduFlowAgent::class, $agent);

    $response = post(route('finance.autonomous-cycle.store'), ['confirmed' => true], [
        'Accept' => 'application/json',
        'X-Requested-With' => 'XMLHttpRequest',
    ]);

    $response->assertSuccessful();
    $content = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n\n", trim($content)), fn (string $block): bool => str_starts_with($block, 'data: ')));
    expect($lines)->toHaveCount(2);

    $startedEvent = json_decode(substr($lines[0], 6), true, 512, JSON_THROW_ON_ERROR);
    $failedEvent = json_decode(substr($lines[1], 6), true, 512, JSON_THROW_ON_ERROR);

    expect($startedEvent['type'])->toBe('cycle_started')
        ->and($failedEvent['type'])->toBe('cycle_failed')
        ->and($failedEvent['sequence'])->toBe(1)
        ->and($failedEvent['run_id'])->toBe($startedEvent['run_id'])
        ->and(Str::isUuid($failedEvent['run_id']))->toBeTrue();

    $insufficientFunds = $failure === 'insufficient_funds';
    $message = $insufficientFunds
        ? 'The treasury wallet does not have enough USDC for the next payment. Check its live Arc balance and allow for network fees.'
        : 'An unexpected error interrupted the cycle. Payment status could not be confirmed.';

    expect($failedEvent['title'])->toBe($insufficientFunds ? 'Cycle stopped: insufficient funds' : 'Cycle stopped before completion')
        ->and($failedEvent['summary'])->toBe($message.' Earlier payments may have completed. Review transactions and reconcile on-chain evidence before running another cycle. Reference: '.$failedEvent['run_id'].'.')
        ->and($failedEvent['reason'])->toBe($insufficientFunds ? 'insufficient_funds' : 'unexpected_error');

    $records = $logs->getRecords();
    expect($records)->toHaveCount(1)
        ->and($records[0]->level->getName())->toBe($insufficientFunds ? 'WARNING' : 'ERROR')
        ->and($records[0]->message)->toBe('Autonomous financial cycle stopped before completion.')
        ->and($records[0]->context)->toBe([
            'reference' => $failedEvent['run_id'],
            'organization_id' => $institution->id,
            'reason' => $insufficientFunds ? 'insufficient_funds' : 'unexpected_error',
            'exception_type' => $exception::class,
        ]);

    Exceptions::assertNothingReported();
    Http::assertNothingSent();

    foreach (['circle wallet transfer', 'test-rpc-secret', 'test-idempotency-key', '<script>'] as $secret) {
        expect($content)->not->toContain($secret)
            ->and(json_encode($records[0]->toArray(), JSON_THROW_ON_ERROR))->not->toContain($secret);
    }

    expect($wallet->fresh()->getAttributes())->toBe($walletBefore)
        ->and($invoice->fresh()->getAttributes())->toBe($invoiceBefore)
        ->and($transaction->fresh()->getAttributes())->toBe($transactionBefore);

    // Verify lock is released and can be acquired immediately
    /** @var LockProvider $cacheStore */
    $cacheStore = Cache::store('database');
    $testLock = $cacheStore->lock('eduflow:autonomous-cycle:'.$institution->id, 10);
    expect($testLock->get())->toBeTrue();
    $testLock->release();
})->with([
    'Circle insufficient funds' => ['insufficient_funds'],
    'unrelated CLI failure' => ['cli_failure'],
    'timeout with unknown payment outcome' => ['timeout'],
    'unrelated exception mentioning insufficient funds' => ['unrelated_insufficient_funds'],
    'CLI arguments mentioning insufficient funds' => ['argument_lookalike'],
]);
