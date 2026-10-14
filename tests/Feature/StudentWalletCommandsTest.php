<?php

declare(strict_types=1);

use App\Models\Student;
use App\Models\StudentWallet;
use App\Services\CircleAgentWalletCli;
use App\Services\StudentWalletGenerationService;
use Illuminate\Support\Facades\Cache;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonCliException;

/**
 * The same stand-in as the generation suite, so a fund run never shells out.
 */
function fundTestCli(array &$wallets, ?Throwable $failure = null): CircleAgentWalletCli
{
    return new class($wallets, $failure) extends CircleAgentWalletCli
    {
        public function __construct(private array &$wallets, private readonly ?Throwable $failure)
        {
            parent::__construct(new CliRunner('true', 5));
        }

        public function agentWalletAddresses(string $chain): array
        {
            return array_map(fn (array $w): string => strtolower($w['address']), $this->wallets);
        }

        public function createAgentWallet(string $idempotencyKey): array
        {
            $this->wallets[] = ['address' => '0x'.str_pad(dechex(crc32($idempotencyKey)), 40, '0', STR_PAD_LEFT)];

            return ['data' => []];
        }

        public function fundWallet(string $address, string $chain): array
        {
            if ($this->failure instanceof Throwable) {
                throw $this->failure;
            }

            return ['data' => ['txHash' => '0x'.str_repeat('c', 64)]];
        }
    };
}

beforeEach(function (): void {
    config(['lepton.arc.chain' => 'ARC-TESTNET']);
    Cache::flush();

    $this->wallets = [];
    app()->instance(CircleAgentWalletCli::class, fundTestCli($this->wallets));
});

test('the fund command does nothing unless a student is named', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    app(StudentWalletGenerationService::class)->generate($student);

    $this->artisan('student:fund-wallet')
        ->expectsOutputToContain('No student given, so nothing was funded.')
        ->assertSuccessful();

    expect($student->wallet->fresh()->fundings()->count())->toBe(0);
});

test('the fund command drips the faucet for a named student', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    app(StudentWalletGenerationService::class)->generate($student);

    $this->artisan('student:fund-wallet', ['student' => $student->id])
        ->assertSuccessful();

    $funding = $student->wallet->fresh()->fundings()->latest('id')->first();

    $notification = $student->user->notifications()->first();

    expect($funding)->not->toBeNull()
        ->and($funding->status->value)->toBe('funded')
        ->and($funding->amount())->toBe('20.0 USDC')
        ->and($notification)->not->toBeNull()
        ->and($notification?->getAttribute('data')['title'])->toBe('Received 20.00 USDC');
});

test('the fund command accepts a student number', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    app(StudentWalletGenerationService::class)->generate($student);

    $this->artisan('student:fund-wallet', ['student' => $student->student_number])
        ->assertSuccessful();

    expect($student->wallet->fresh()->fundings()->count())->toBe(1);
});

test('the fund command reports an unknown student', function (): void {
    $this->artisan('student:fund-wallet', ['student' => 'STU-404404'])
        ->expectsOutputToContain('No student found for [STU-404404]')
        ->assertFailed();
});

test('--all funds every student that has a wallet and skips the rest', function (): void {
    $service = app(StudentWalletGenerationService::class);

    $first = Student::factory()->withoutPayoutAddress()->create();
    $second = Student::factory()->withoutPayoutAddress()->create();
    Student::factory()->withoutPayoutAddress()->create();

    $service->generate($first);
    $service->generate($second);

    $this->artisan('student:fund-wallet', ['--all' => true])->assertSuccessful();

    expect($first->wallet->fresh()->fundings()->count())->toBe(1)
        ->and($second->wallet->fresh()->fundings()->count())->toBe(1);
});

test('a failed drip makes the command fail rather than exit clean', function (): void {
    app()->instance(CircleAgentWalletCli::class, fundTestCli(
        $this->wallets,
        new LeptonCliException(command: ['wallet', 'fund'], exitCode: 1, stderr: 'Faucet drip failed (429): API rate limit error'),
    ));

    $student = Student::factory()->withoutPayoutAddress()->create();
    app(StudentWalletGenerationService::class)->generate($student);

    $this->artisan('student:fund-wallet', ['student' => $student->id])->assertFailed();

    $funding = $student->wallet->fresh()->fundings()->latest('id')->first();

    expect($funding->status->value)->toBe('failed')
        ->and($funding->detail)->toContain('rate-limits');
});

test('the list option prints the wallets without dripping', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    app(StudentWalletGenerationService::class)->generate($student);

    $this->artisan('student:fund-wallet', ['--list' => true])->assertSuccessful();

    expect(StudentWallet::query()->count())->toBe(1)
        ->and($student->wallet->fresh()->fundings()->count())->toBe(0);
});

test('the generate command mints a wallet for a named student', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();

    $this->artisan('student:generate-wallet', ['student' => $student->student_number])
        ->assertSuccessful();

    expect($student->wallet)->not->toBeNull()
        ->and($student->fresh()->payout_address)->toBe($student->wallet->address);
});

test('the generate command refuses when all agent-wallet slots are spent', function (): void {
    for ($i = 0; $i < 5; $i++) {
        $this->wallets[] = ['address' => '0x'.str_pad(dechex($i + 1), 40, '0', STR_PAD_LEFT)];
    }

    $student = Student::factory()->withoutPayoutAddress()->create();

    $this->artisan('student:generate-wallet', ['student' => $student->id])->assertFailed();

    expect(StudentWallet::query()->count())->toBe(0)
        ->and($student->fresh()->payout_address)->toBeNull();
});
