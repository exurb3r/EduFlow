<?php

declare(strict_types=1);

use App\Enums\TransactionType;
use App\Models\AcademicTerm;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\Wallet;
use App\Services\CircleAgentWalletCli;
use App\Services\StudentTuitionPaymentService;
use App\Services\StudentWalletGenerationService;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\TransferResult;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonCliException;

/**
 * A Circle CLI stand-in. The transfer returns a hash and the fee the chain
 * charged; the estimate returns the fee a transfer would cost.
 */
function payTestCli(array &$wallets): CircleAgentWalletCli
{
    return new class($wallets) extends CircleAgentWalletCli
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
            return ['data' => ['txHash' => '0x'.str_repeat('c', 64)]];
        }
    };
}

beforeEach(function (): void {
    config([
        'lepton.default' => 'fake',
        'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.treasury' => '0x2629de3b67042842ab2a2e33a1bd81ca583d975c',
    ]);
    Cache::flush();

    foreach (['student', 'admin', 'super_admin', 'finance_officer', 'user'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $this->wallets = [];
    app()->instance(CircleAgentWalletCli::class, payTestCli($this->wallets));

    $this->organization = Organization::factory()->create(['name' => 'Northstar']);
    $this->treasury = Wallet::factory()->create([
        'organization_id' => $this->organization->id,
        'address' => (string) config('lepton.arc.treasury'),
        'balance' => 97.00,
        'status' => 'active',
    ]);
});

/**
 * A student with a funded wallet and tuition still owed.
 */
function payingStudent(float $walletUsdc = 20.0, float $tuitionUsdc = 300.0, float $paidUsdc = 0.0): array
{
    $student = Student::factory()->withoutPayoutAddress()->create();
    $wallet = app(StudentWalletGenerationService::class)->generate($student);

    app(FakeLeptonGateway::class)
        ->seedBalance($wallet->address, (int) round($walletUsdc * 1_000_000));

    $term = AcademicTerm::factory()->create();
    $account = TuitionAccount::factory()->for($student)->for($term)->create([
        'total_amount' => (int) round($tuitionUsdc * 1_000_000),
        'paid_amount' => (int) round($paidUsdc * 1_000_000),
    ]);

    return [$student, $account, $wallet];
}

test('the quote reports the amount, what is owed, and the destination', function (): void {
    [$student, $account] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0);

    $quote = app(StudentTuitionPaymentService::class)->quote($student, $account, 5_000000);

    expect($quote['amount_base_units'])->toBe(5_000000)
        ->and($quote['remaining_base_units'])->toBe(300_000000)
        ->and($quote['recipient'])->toBe('0x2629de3b67042842ab2a2e33a1bd81ca583d975c')
        ->and($quote['chain'])->toBe('ARC-TESTNET')
        ->and($quote['balance'])->toBe('20.0');
});

test('the quote refuses an amount larger than the wallet can cover', function (): void {
    [$student, $account] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0);

    // 20 is affordable, 20.01 is not once the fee is added.
    expect(fn () => app(StudentTuitionPaymentService::class)->quote($student, $account, 20_000000))
        ->not->toThrow(RuntimeException::class);

    expect(fn () => app(StudentTuitionPaymentService::class)->quote($student, $account, 20_010000))
        ->toThrow(RuntimeException::class, 'network fees');
});

test('the quote refuses an amount larger than what is owed', function (): void {
    [$student, $account] = payingStudent(walletUsdc: 900.0, tuitionUsdc: 300.0);

    expect(fn () => app(StudentTuitionPaymentService::class)->quote($student, $account, 400_000000))
        ->toThrow(RuntimeException::class, 'still owed');
});

test('paying moves the amount, records the fee, and reduces what is owed', function (): void {
    [$student, $account, $wallet] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0);

    $payment = app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000);

    expect($payment->amount())->toBe('5.0 USDC')
        ->and($payment->network_fee)->toBe('0.000000')
        ->and($account->fresh()->remainingAmount())->toBe(295_000000)
        ->and($payment->recipient_address)->toBe('0x2629de3b67042842ab2a2e33a1bd81ca583d975c')
        ->and($payment->studentWallet->is($wallet))->toBeTrue();
});

test('the fee is recorded as a cost the student bore, on their receipt and the ledger', function (): void {
    [$student, $account] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0);

    $payment = app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000);

    $transaction = Transaction::query()
        ->where('reference_type', StudentTuitionPayment::class)
        ->where('reference_id', $payment->getKey())
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->type)->toBe(TransactionType::TUITION_REVENUE)
        ->and($transaction->recipient_address)->toBe('0x2629de3b67042842ab2a2e33a1bd81ca583d975c')
        ->and($transaction->metadata['network_fee'])->toBe('0.000000')
        ->and($transaction->metadata['source'])->toBe('student_portal_pay_now');
});

test('paying is refused for a student with no generated wallet', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    $term = AcademicTerm::factory()->create();
    $account = TuitionAccount::factory()->for($student)->for($term)->create([
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    expect(fn () => app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000))
        ->toThrow(RuntimeException::class, 'no generated wallet');

    expect(StudentTuitionPayment::query()->count())->toBe(0);
});

test('paying is refused when the payout address is no longer the generated wallet', function (): void {
    [$student, $account] = payingStudent();

    // Staff re-point the payout address somewhere the CLI cannot sign for.
    $student->update(['payout_address' => '0x'.str_repeat('f', 40)]);

    expect(fn () => app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000))
        ->toThrow(RuntimeException::class, 'not the generated wallet');

    expect(StudentTuitionPayment::query()->count())->toBe(0);
});

test('an account that is already settled is refused', function (): void {
    [$student, $account] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0, paidUsdc: 300.0);

    expect(fn () => app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000))
        ->toThrow(RuntimeException::class, 'already settled');
});

test('another students tuition account cannot be paid', function (): void {
    [$student] = payingStudent();
    $term = AcademicTerm::factory()->create();
    $theirs = TuitionAccount::factory()->create([
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    expect(fn () => app(StudentTuitionPaymentService::class)->pay($student, $theirs, 5_000000))
        ->toThrow(InvalidArgumentException::class, 'does not belong');
});

test('a failed transfer records nothing', function (): void {
    [$student, $account] = payingStudent();

    // The transfer itself is refused by the chain, not by the quote.
    app()->instance(
        WalletGateway::class,
        new class implements WalletGateway
        {
            public function transfer(string $from, string $to, int $baseUnits, array $options = []): TransferResult
            {
                throw new LeptonCliException(
                    command: ['wallet', 'transfer'],
                    exitCode: 1,
                    stderr: 'Error: Service returned error 400: API parameter invalid',
                );
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
        },
    );

    expect(fn () => app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000))
        ->toThrow(RuntimeException::class);

    expect(StudentTuitionPayment::query()->count())->toBe(0)
        ->and($account->fresh()->remainingAmount())->toBe(300_000000)
        ->and($this->treasury->fresh()->balance)->toBe(97.00);
});

test('the recipient always comes from configuration, never from the request', function (): void {
    [$student, $account] = payingStudent();

    $quote = app(StudentTuitionPaymentService::class)->quote($student, $account, 5_000000);

    expect($quote['recipient'])->toBe((string) config('lepton.arc.treasury'));
});
