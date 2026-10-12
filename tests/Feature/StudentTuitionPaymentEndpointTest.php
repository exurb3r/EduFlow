<?php

declare(strict_types=1);

use App\Models\AcademicTerm;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CircleAgentWalletCli;
use App\Services\StudentWalletGenerationService;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\TransferResult;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonCliException;

function payEndpointStudent(float $walletUsdc = 20.0, float $tuitionUsdc = 300.0): array
{
    $student = Student::factory()->withoutPayoutAddress()->create();
    $wallet = app(StudentWalletGenerationService::class)->generate($student);

    app(FakeLeptonGateway::class)
        ->seedBalance($wallet->address, (int) round($walletUsdc * 1_000_000));

    $account = TuitionAccount::factory()->for($student)->for(AcademicTerm::factory())->create([
        'total_amount' => (int) round($tuitionUsdc * 1_000_000),
        'paid_amount' => 0,
    ]);

    return [$student, $account, $wallet];
}

beforeEach(function (): void {
    config([
        'lepton.default' => 'fake',
        'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.treasury' => '0x2629de3b67042842ab2a2e33a1bd81ca583d975c',
    ]);
    Cache::flush();

    foreach (['student', 'admin', 'super_admin'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $wallets = [];
    app()->instance(CircleAgentWalletCli::class, new class($wallets) extends CircleAgentWalletCli
    {
        public function __construct(private array &$wallets)
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

            return [];
        }

        public function estimateTransfer(string $from, string $to, int $baseUnits, string $chain): array
        {
            return ['data' => ['networkFee' => '0.02945113436514256']];
        }

        public function fundWallet(string $address, string $chain): array
        {
            return [];
        }
    });

    $organization = Organization::factory()->create();
    Wallet::factory()->create([
        'organization_id' => $organization->id,
        'address' => (string) config('lepton.arc.treasury'),
        'balance' => 97.00,
        'status' => 'active',
    ]);
});

test('a student can quote and pay their own tuition', function (): void {
    [$student, $account] = payEndpointStudent();

    $this->actingAs($student->user->assignRole('student'));

    $quote = $this->postJson(route('student.tuition.quote'), [
        'tuition_account_id' => $account->id,
        'amount_base_units' => 5_000000,
    ]);

    $quote->assertOk()->assertJsonPath('amount_base_units', 5_000000);

    $paid = $this->postJson(route('student.tuition.pay'), [
        'tuition_account_id' => $account->id,
        'amount_base_units' => 5_000000,
    ]);

    $paid->assertOk()
        ->assertJsonPath('amount', '5.0 USDC')
        ->assertJsonPath('remaining', 295);

    expect($account->fresh()->remainingAmount())->toBe(295_000000);
});

test('a guest cannot pay tuition', function (): void {
    [$student, $account] = payEndpointStudent();

    $this->postJson(route('student.tuition.pay'), [
        'tuition_account_id' => $account->id,
        'amount_base_units' => 5_000000,
    ])->assertUnauthorized();
});

test('a student cannot pay another students tuition account', function (): void {
    [$student] = payEndpointStudent();
    $term = AcademicTerm::factory()->create();
    $theirs = TuitionAccount::factory()->for($term)->create([
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    $this->actingAs($student->user->assignRole('student'));

    $this->postJson(route('student.tuition.pay'), [
        'tuition_account_id' => $theirs->id,
        'amount_base_units' => 5_000000,
    ])->assertNotFound();

    expect($theirs->fresh()->paid_amount)->toBe(0);
});

test('a non-student cannot reach the payment endpoints', function (): void {
    [$student, $account] = payEndpointStudent();

    $this->actingAs(User::factory()->create()->assignRole('admin'));

    $this->postJson(route('student.tuition.pay'), [
        'tuition_account_id' => $account->id,
        'amount_base_units' => 5_000000,
    ])->assertForbidden();
});

test('a chain failure is reported, not recorded', function (): void {
    [$student, $account] = payEndpointStudent();

    app()->instance(WalletGateway::class, new class implements WalletGateway
    {
        public function transfer(string $from, string $to, int $baseUnits, array $options = []): TransferResult
        {
            throw new LeptonCliException(command: ['wallet', 'transfer'], exitCode: 1, stderr: 'insufficient funds for gas');
        }

        public function balance(string $address, array $options = []): BalanceResult
        {
            return new BalanceResult($address, 0, 'ARC-TESTNET', true);
        }

        public function transactions(string $address, array $filters = []): array
        {
            return [];
        }

        public function limits(string $address, array $options = []): array
        {
            return [];
        }
    });

    $this->actingAs($student->user->assignRole('student'));

    $this->postJson(route('student.tuition.pay'), [
        'tuition_account_id' => $account->id,
        'amount_base_units' => 5_000000,
    ])->assertStatus(500);

    expect(StudentTuitionPayment::query()->count())->toBe(0)
        ->and($account->fresh()->remainingAmount())->toBe(300_000000);
});
