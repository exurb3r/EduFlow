<?php

declare(strict_types=1);

use App\Enums\StudentWalletFundingStatus;
use App\Models\Student;
use App\Models\StudentWallet;
use App\Models\StudentWalletFunding;
use App\Services\CircleAgentWalletCli;
use App\Services\StudentWalletGenerationService;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonCliException;

/**
 * A Circle CLI stand-in: the wallet list is what the service reads to find the
 * newly minted address, so it is expressed as state the test controls.
 */
function fakeCircleCli(array &$wallets, ?Throwable $fundFailure = null): CircleAgentWalletCli
{
    return new class($wallets, $fundFailure) extends CircleAgentWalletCli
    {
        public function __construct(private array &$wallets, private readonly ?Throwable $fundFailure)
        {
            parent::__construct(new CliRunner('true', 5));
        }

        public function agentWalletAddresses(string $chain): array
        {
            return array_values(array_filter(
                array_map(fn (array $w): string => strtolower($w['address']), $this->wallets),
            ));
        }

        public function createAgentWallet(string $idempotencyKey): array
        {
            $address = '0x'.str_pad(dechex(crc32($idempotencyKey)), 40, '0', STR_PAD_LEFT);
            $this->wallets[] = ['address' => $address];

            return ['data' => ['wallets' => []]];
        }

        public function fundWallet(string $address, string $chain): array
        {
            if ($this->fundFailure instanceof Throwable) {
                throw $this->fundFailure;
            }

            return ['data' => ['txHash' => '0x'.str_repeat('a', 64)]];
        }
    };
}

beforeEach(function (): void {
    config(['lepton.arc.chain' => 'ARC-TESTNET']);
    Cache::flush();

    $this->wallets = [];

    app()->instance(
        CircleAgentWalletCli::class,
        fakeCircleCli($this->wallets),
    );
});

test('generating a wallet mints one, records it, and makes it the payout address', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();

    $wallet = app(StudentWalletGenerationService::class)->generate($student);

    expect($wallet->address)->toStartWith('0x')
        ->and(strlen($wallet->address))->toBe(42)
        ->and($wallet->chain)->toBe('ARC-TESTNET')
        ->and($wallet->provider)->toBe('circle')
        ->and($student->fresh()->payout_address)->toBe($wallet->address)
        ->and($this->wallets)->toHaveCount(1);
});

test('the minted address is read from the CLI list, never synthesised', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();

    $wallet = app(StudentWalletGenerationService::class)->generate($student);

    // The address recorded is the one the CLI reported back, unchanged.
    expect($wallet->address)->toBe(strtolower($this->wallets[0]['address']))
        ->and($student->fresh()->payout_address)->toBe($this->wallets[0]['address']);
});

test('a student cannot be given a second generated wallet', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    $service = app(StudentWalletGenerationService::class);

    $service->generate($student);

    expect(fn () => $service->generate($student))
        ->toThrow(RuntimeException::class, 'already has a generated wallet');

    expect($this->wallets)->toHaveCount(1);
});

test('generation is refused once Circle runs out of agent-wallet slots', function (): void {
    // Four pre-existing wallets plus the one this generation wants makes five,
    // so a second student must be refused before anything is minted.
    for ($i = 0; $i < 5; $i++) {
        $this->wallets[] = ['address' => '0x'.str_pad(dechex($i + 1), 40, '0', STR_PAD_LEFT)];
    }

    $student = Student::factory()->withoutPayoutAddress()->create();

    expect(fn () => app(StudentWalletGenerationService::class)->generate($student))
        ->toThrow(RuntimeException::class, 'Circle allows 5 agent wallets per blockchain');

    expect($this->wallets)->toHaveCount(5)
        ->and($student->fresh()->payout_address)->toBeNull();
});

test('a wallet the CLI fails to report is never recorded', function (): void {
    $existing = [['address' => '0x'.str_repeat('1', 40)]];
    $this->wallets = $existing;

    $cli = new class($existing) extends CircleAgentWalletCli
    {
        public function __construct(private readonly array $wallets)
        {
            parent::__construct(new CliRunner('true', 5));
        }

        public function agentWalletAddresses(string $chain): array
        {
            return array_map(fn (array $w): string => strtolower($w['address']), $this->wallets);
        }

        public function createAgentWallet(string $idempotencyKey): array
        {
            return ['data' => []];
        }
    };

    app()->instance(CircleAgentWalletCli::class, $cli);

    $student = Student::factory()->withoutPayoutAddress()->create();

    expect(fn () => app(StudentWalletGenerationService::class)->generate($student))
        ->toThrow(RuntimeException::class, 'no new wallet address');

    expect(StudentWallet::query()->count())->toBe(0)
        ->and($student->fresh()->payout_address)->toBeNull();
});

test('funding a generated wallet records the drip', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    $service = app(StudentWalletGenerationService::class);
    $wallet = $service->generate($student);

    $funding = $service->fund($student);

    expect($funding->status)->toBe(StudentWalletFundingStatus::FUNDED)
        ->and($funding->amount())->toBe('20.0 USDC')
        ->and($funding->chain)->toBe('ARC-TESTNET')
        ->and($funding->tx_hash)->toStartWith('0x')
        ->and($wallet->fresh()->fundings()->count())->toBe(1);
});

test('funding a student with no generated wallet fails closed', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();

    expect(fn () => app(StudentWalletGenerationService::class)->fund($student))
        ->toThrow(RuntimeException::class, 'No generated wallet');
});

test('funding is refused when the payout address no longer matches the minted wallet', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    $service = app(StudentWalletGenerationService::class);
    $service->generate($student);

    // Staff re-point the payout address; the faucet would reject it.
    $student->update(['payout_address' => '0x'.str_repeat('f', 40)]);

    expect(fn () => $service->fund($student))
        ->toThrow(RuntimeException::class, 'not the generated wallet');

    expect(StudentWalletFunding::query()->count())->toBe(0);
});

test('a faucet rejection is recorded as a failed drip rather than swallowed', function (): void {
    $existing = [];
    $cli = fakeCircleCli($existing, new LeptonCliException(command: ['wallet', 'fund'], exitCode: 1, stderr: 'Faucet drip failed (429): API rate limit error'));

    app()->instance(CircleAgentWalletCli::class, $cli);

    $student = Student::factory()->withoutPayoutAddress()->create();
    $service = app(StudentWalletGenerationService::class);
    $service->generate($student);

    $funding = $service->fund($student);

    expect($funding->status)->toBe(StudentWalletFundingStatus::FAILED)
        ->and($funding->detail)->toContain('rate-limits')
        ->and($funding->amount())->toBe('20.0 USDC');
});

test('an unauthenticated CLI reports the login step', function (): void {
    $existing = [];
    $cli = fakeCircleCli($existing, new LeptonCliException(command: ['wallet', 'fund'], exitCode: 1, stderr: 'Not logged in (or session expired). Run `circle wallet login <email>` and retry.'));

    app()->instance(CircleAgentWalletCli::class, $cli);

    $student = Student::factory()->withoutPayoutAddress()->create();
    $service = app(StudentWalletGenerationService::class);
    $service->generate($student);

    $funding = $service->fund($student);

    expect($funding->status)->toBe(StudentWalletFundingStatus::FAILED)
        ->and($funding->detail)->toContain('not logged in');
});

test('the remaining slot count reflects the wallets Circle already holds', function (): void {
    $this->wallets[] = ['address' => '0x'.str_repeat('a', 40)];
    $this->wallets[] = ['address' => '0x'.str_repeat('b', 40)];

    expect(app(StudentWalletGenerationService::class)->remainingSlots())->toBe(3);
});

test('the idempotency key is a valid uuid v5 derived from the student', function (): void {
    $method = new ReflectionMethod(StudentWalletGenerationService::class, 'idempotencyKeyFor');
    $service = app(StudentWalletGenerationService::class);

    $key = $method->invoke($service, Student::factory()->create());

    // Circle accepts nothing else: a plain string is a 400 "API parameter
    // invalid" that names nothing.
    expect($key)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

test('the idempotency key is stable, so a retry cannot mint a second wallet', function (): void {
    $method = new ReflectionMethod(StudentWalletGenerationService::class, 'idempotencyKeyFor');
    $service = app(StudentWalletGenerationService::class);

    $student = Student::factory()->create();

    expect($method->invoke($service, $student))->toBe($method->invoke($service, $student->fresh()));
});

test('each student gets a distinct idempotency key', function (): void {
    $method = new ReflectionMethod(StudentWalletGenerationService::class, 'idempotencyKeyFor');
    $service = app(StudentWalletGenerationService::class);

    $first = Student::factory()->create();
    $second = Student::factory()->create();

    expect($method->invoke($service, $first))->not->toBe($method->invoke($service, $second));
});
