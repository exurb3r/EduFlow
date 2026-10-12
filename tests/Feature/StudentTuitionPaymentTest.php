<?php

declare(strict_types=1);

use App\Enums\TransactionType;
use App\Models\AcademicTerm;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\Wallet;
use App\Services\CircleAgentWalletCli;
use App\Services\StudentTuitionPaymentService;
use App\Services\StudentWalletGenerationService;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
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

/**
 * The treasury balance as the ledger holds it.
 *
 * Read through the query rather than off a refreshed model, so the assertion
 * states the figure under test instead of a property the type cannot promise.
 * The column is numeric, so the value is compared as an amount rather than as
 * a formatted string: how it renders is not what this assertion is about.
 */
function treasuryBalance(): float
{
    return (float) Wallet::query()
        ->where('address', (string) config('lepton.arc.treasury'))
        ->value('balance');
}

// ---------------------------------------------------------------------------
// Quoting, before anything is spent
// ---------------------------------------------------------------------------

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

test('a fee the rail cannot read is never reported as free', function (): void {
    [$student, $account, $wallet] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0);

    // A rehearsal is not quoted at all, so the rail must be one that could
    // really charge before this question means anything.
    settlingArc($wallet->address, (string) config('lepton.arc.treasury'), 5_000000);

    app()->instance(
        CircleAgentWalletCli::class,
        new class extends CircleAgentWalletCli
        {
            public function __construct()
            {
                parent::__construct(new CliRunner('true', 5));
            }

            public function agentWalletAddresses(string $chain): array
            {
                return [];
            }

            public function estimateTransfer(string $from, string $to, int $baseUnits, string $chain): array
            {
                throw new LeptonCliException(command: ['circle', 'wallet', 'estimate-transfer'], exitCode: 1, stderr: 'connection refused');
            }
        },
    );

    // A payer told the transfer is free will be charged one, and the difference
    // is money they never agreed to spend.
    expect(fn () => app(StudentTuitionPaymentService::class)->quote($student, $account, 5_000000))
        ->toThrow(RuntimeException::class, 'fee could not be read');
});

test('the quote refuses an amount larger than what is owed', function (): void {
    [$student, $account] = payingStudent(walletUsdc: 900.0, tuitionUsdc: 300.0);

    expect(fn () => app(StudentTuitionPaymentService::class)->quote($student, $account, 400_000000))
        ->toThrow(RuntimeException::class, 'still owed');
});

test('the recipient always comes from configuration, never from the request', function (): void {
    [$student, $account] = payingStudent();

    $quote = app(StudentTuitionPaymentService::class)->quote($student, $account, 5_000000);

    expect($quote['recipient'])->toBe((string) config('lepton.arc.treasury'));
});

// ---------------------------------------------------------------------------
// Refusals, before a signer is reached
// ---------------------------------------------------------------------------

test('paying is refused for a student with no generated wallet', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    $term = AcademicTerm::factory()->create();
    $account = TuitionAccount::factory()->for($student)->for($term)->create([
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    expect(fn () => app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000, '00000000-0000-0000-0000-000000000001'))
        ->toThrow(RuntimeException::class, 'no generated wallet');

    expect(StudentTuitionPayment::query()->count())->toBe(0);
});

test('paying is refused when the payout address is no longer the generated wallet', function (): void {
    [$student, $account] = payingStudent();

    // Staff re-point the payout address somewhere the CLI cannot sign for.
    $student->update(['payout_address' => '0x'.str_repeat('f', 40)]);

    expect(fn () => app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000, '00000000-0000-0000-0000-000000000002'))
        ->toThrow(RuntimeException::class, 'not the generated wallet');

    expect(StudentTuitionPayment::query()->count())->toBe(0);
});

test('an account that is already settled is refused', function (): void {
    [$student, $account] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0, paidUsdc: 300.0);

    expect(fn () => app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000, '00000000-0000-0000-0000-000000000003'))
        ->toThrow(RuntimeException::class, 'already settled');
});

test('another students tuition account cannot be paid', function (): void {
    [$student] = payingStudent();
    $term = AcademicTerm::factory()->create();
    $theirs = TuitionAccount::factory()->create([
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    expect(fn () => app(StudentTuitionPaymentService::class)->pay($student, $theirs, 5_000000, '00000000-0000-0000-0000-000000000004'))
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

    expect(fn () => app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000, '00000000-0000-0000-0000-000000000005'))
        ->toThrow(RuntimeException::class);

    expect(StudentTuitionPayment::query()->count())->toBe(0)
        ->and($account->fresh()->remainingAmount())->toBe(300_000000)
        ->and(treasuryBalance())->toBe(97.00);
});

// ---------------------------------------------------------------------------
// The chain's answer, not the caller's
// ---------------------------------------------------------------------------

/**
 * An Arc gateway that answers chain reads for one specific movement.
 *
 * It is a plain implementation rather than a mock of the fake driver, because
 * the fake driver cannot answer a receipt: exercising the verified path
 * honestly requires reads that describe a real transaction, which is the only
 * way this path can be tested before a payment provider is configured.
 */
class SettlingArc implements ArcNetworkGateway
{
    /** @var array<string, mixed> */
    private readonly array $receipt;

    public function __construct(
        private readonly string $treasury,
        private readonly string $payer,
        private readonly string $recipient,
        private readonly int $amountBaseUnits,
        array $receiptOverrides = [],
    ) {
        $this->receipt = $receiptOverrides + [
            'transactionHash' => '0x'.str_repeat('f', 64),
            'blockNumber' => '0x64',
            'status' => '0x1',
            'gasUsed' => '0x5208',
            'effectiveGasPrice' => '0x1',
            'logs' => [[
                // Arc's native system emitter, so the movement is 18 decimals.
                'address' => '0xfffffffffffffffffffffffffffffffffffffffe',
                'topics' => [
                    '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                    '0x'.str_repeat('0', 24).ltrim(strtolower($payer), '0x'),
                    '0x'.str_repeat('0', 24).ltrim(strtolower($recipient), '0x'),
                ],
                'data' => '0x'.BigInteger::of($amountBaseUnits)->multipliedBy(BigInteger::of('1000000000000'))->toBase(16),
            ]],
        ];
    }

    public function rpcUrl(): string
    {
        return 'https://rpc.testnet.arc.network';
    }

    public function chainCode(): string
    {
        return 'ARC-TESTNET';
    }

    public function chainId(): int
    {
        return 5042002;
    }

    public function blockNumber(): string
    {
        return '0x64';
    }

    public function treasuryAddress(): ?string
    {
        return $this->treasury;
    }

    public function explorerUrl(string $txHash): string
    {
        return 'https://explorer.testnet.arc.network/tx/'.$txHash;
    }

    public function addressExplorerUrl(string $address): string
    {
        return 'https://explorer.testnet.arc.network/address/'.$address;
    }

    public function rpc(string $method, array $params = []): mixed
    {
        return match ($method) {
            // The wallet balance is read from this same stream in the non-fake
            // branch, at Arc's 18 decimals.
            'eth_getBalance' => '0x'.BigInteger::of($this->amountBaseUnits + 10_000000)->multipliedBy(BigInteger::of('1000000000000'))->toBase(16),
            'eth_getTransactionReceipt' => $this->receipt,
            'eth_getTransactionByHash' => [
                'from' => $this->payer,
                'to' => $this->recipient,
                'value' => '0x'.BigInteger::of($this->amountBaseUnits)->multipliedBy(BigInteger::of('1000000000000'))->toBase(16),
                'blockNumber' => '0x64',
            ],
            'eth_getBlockByNumber' => ['number' => '0x64', 'hash' => '0x'.str_repeat('a', 64), 'timestamp' => '0x65'],
            default => null,
        };
    }
}

/**
 * A wallet rail that reports the movement the Arc gateway describes.
 *
 * It is deliberately not the fake driver: the fake driver short-circuits the
 * whole verification question, which is exactly what must not be assumed.
 */
final readonly class SettlingWallet implements WalletGateway
{
    public function __construct(private string $txHash) {}

    public function transfer(string $from, string $to, int $baseUnits, array $options = []): TransferResult
    {
        return new TransferResult(
            txHash: $this->txHash,
            fromAddress: $from,
            toAddress: $to,
            amountBaseUnits: $baseUnits,
            chain: $options['chain'] ?? 'ARC-TESTNET',
            isFake: false,
            explorerUrl: 'https://explorer.testnet.arc.network/tx/'.$this->txHash,
            raw: ['data' => ['networkFee' => '0.000000']],
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
}

/**
 * Point the installation at a chain that describes one exact movement.
 */
function settlingArc(string $payer, string $recipient, int $amountBaseUnits, array $receiptOverrides = []): void
{
    app()->instance(ArcNetworkGateway::class, new SettlingArc(
        treasury: (string) config('lepton.arc.treasury'),
        payer: $payer,
        recipient: $recipient,
        amountBaseUnits: $amountBaseUnits,
        receiptOverrides: $receiptOverrides,
    ));

    app()->instance(WalletGateway::class, new SettlingWallet('0x'.str_repeat('f', 64)));
}

test('a payment is only recorded once the chain says the exact amount moved', function (): void {
    [$student, $account, $wallet] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0);

    settlingArc($wallet->address, (string) config('lepton.arc.treasury'), 5_000000);

    $payment = app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000, '00000000-0000-0000-0000-0000000000a1');

    expect($payment->isReal())->toBeTrue()
        ->and($payment->tx_verified_on_chain)->toBeTrue()
        ->and($payment->applied_base_units)->toBe(5_000000)
        ->and($payment->overpayment_base_units)->toBe(0)
        // The debt is written from the verified allocation, not from input.
        ->and($account->fresh()->remainingAmount())->toBe(295_000000);

    $attempt = StudentTuitionPaymentAttempt::query()->where('request_key', '00000000-0000-0000-0000-0000000000a1')->first();

    expect($attempt->isVerified())->toBeTrue()
        ->and($attempt->settlement_verdict)->toBe('verified')
        ->and($attempt->provider_reference)->toBe('0x'.str_repeat('f', 64));

    $transaction = Transaction::query()
        ->where('reference_type', StudentTuitionPayment::class)
        ->where('reference_id', $payment->getKey())
        ->first();

    expect($transaction)->not->toBeNull()
        ->and($transaction->type)->toBe(TransactionType::TUITION_REVENUE)
        ->and($transaction->metadata['amount_base_units'])->toBe(5_000000)
        ->and($transaction->metadata['source'])->toBe('student_portal_pay_now');
});

test('a transaction that exists but moved the wrong amount records nothing', function (): void {
    [$student, $account, $wallet] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0);

    // The merged defect this replaces: the hash exists, the receipt executed,
    // and only the amount is wrong. Presence was being read as settlement.
    settlingArc($wallet->address, (string) config('lepton.arc.treasury'), 1_000000);

    expect(fn () => app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000, '00000000-0000-0000-0000-0000000000b2'))
        ->toThrow(RuntimeException::class, 'could not be confirmed on chain');

    expect(StudentTuitionPayment::query()->count())->toBe(0)
        ->and($account->fresh()->remainingAmount())->toBe(300_000000)
        ->and(Transaction::query()->where('type', TransactionType::TUITION_REVENUE)->count())->toBe(0);

    // The attempt survives so it is observed and reconciled, not sent again.
    $attempt = StudentTuitionPaymentAttempt::query()->where('request_key', '00000000-0000-0000-0000-0000000000b2')->first();

    expect($attempt->isVerified())->toBeFalse()
        ->and($attempt->settlement_verdict)->toBe('mismatched')
        ->and($attempt->state)->toBe('awaiting_verification');
});

test('a reverted receipt is a final failure, never a pending state', function (): void {
    [$student, $account, $wallet] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0);

    settlingArc($wallet->address, (string) config('lepton.arc.treasury'), 5_000000, [
        'status' => '0x0',
        'logs' => [],
    ]);

    expect(fn () => app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000, '00000000-0000-0000-0000-0000000000c3'))
        ->toThrow(RuntimeException::class, 'could not be confirmed on chain');

    expect(StudentTuitionPayment::query()->count())->toBe(0);

    $attempt = StudentTuitionPaymentAttempt::query()->where('request_key', '00000000-0000-0000-0000-0000000000c3')->first();

    expect($attempt->settlement_verdict)->toBe('reverted')
        ->and($attempt->state)->toBe('awaiting_verification');
});

test('confirming the same payment twice credits tuition once', function (): void {
    [$student, $account, $wallet] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0);

    settlingArc($wallet->address, (string) config('lepton.arc.treasury'), 5_000000);

    $service = app(StudentTuitionPaymentService::class);

    $first = $service->pay($student, $account, 5_000000, '00000000-0000-0000-0000-0000000000d4');
    $second = $service->pay($student, $account, 5_000000, '00000000-0000-0000-0000-0000000000d4');

    expect($second->is($first))->toBeTrue()
        ->and($account->fresh()->remainingAmount())->toBe(295_000000)
        ->and(StudentTuitionPayment::query()->count())->toBe(1)
        ->and(Transaction::query()->where('type', TransactionType::TUITION_REVENUE)->count())->toBe(1);
});

test('a debt that shrinks after the payer confirmed lands the remainder as an overpayment', function (): void {
    [$student, $account, $wallet] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0);

    // Staff reduce what is owed after the payer confirmed — a fee is waived, a
    // concession applied — so the amount already authorized is now larger than
    // the debt. This is the race the overpayment bucket exists for, not a
    // licence to pay more than is owed through the form.
    $GLOBALS['__shrink_account'] = $account->getKey();

    settlingArc($wallet->address, (string) config('lepton.arc.treasury'), 15_000000);

    app()->instance(WalletGateway::class, new class('0x'.str_repeat('f', 64)) implements WalletGateway
    {
        public function __construct(private readonly string $txHash) {}

        public function transfer(string $from, string $to, int $baseUnits, array $options = []): TransferResult
        {
            TuitionAccount::query()->whereKey($GLOBALS['__shrink_account'])->update(['total_amount' => 10_000000]);

            return new TransferResult(
                txHash: $this->txHash,
                fromAddress: $from,
                toAddress: $to,
                amountBaseUnits: $baseUnits,
                chain: $options['chain'] ?? 'ARC-TESTNET',
                isFake: false,
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
    });

    $payment = app(StudentTuitionPaymentService::class)->pay($student, $account, 15_000000, '00000000-0000-0000-0000-0000000000e5');

    expect($payment->applied_base_units)->toBe(10_000000)
        ->and($payment->overpayment_base_units)->toBe(5_000000)
        // The debt is capped at what was owed, so the excess never reads as
        // settled tuition and never becomes budget cash.
        ->and($account->fresh()->remainingAmount())->toBe(0)
        ->and($payment->overpayment())->toBe('5.0 USDC');
});

test('a rehearsal without a payment provider records no debt and no ledger row', function (): void {
    [$student, $account, $wallet] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0);

    $payment = app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000, '00000000-0000-0000-0000-0000000000f6');

    expect($payment->isReal())->toBeFalse()
        ->and($payment->simulated)->toBeTrue()
        ->and($payment->tx_verified_on_chain)->toBeFalse();

    // Nothing moved, so nothing is credited anywhere that plans money.
    expect($account->fresh()->remainingAmount())->toBe(300_000000)
        ->and(treasuryBalance())->toBe(97.00)
        ->and(Transaction::query()->where('type', TransactionType::TUITION_REVENUE)->count())->toBe(0);

    $attempt = StudentTuitionPaymentAttempt::query()->where('request_key', '00000000-0000-0000-0000-0000000000f6')->first();

    expect($attempt->state)->toBe('prepared');
});

test('an unreadable chain is an unresolved attempt, not a claim that no payment happened', function (): void {
    [$student, $account, $wallet] = payingStudent(walletUsdc: 20.0, tuitionUsdc: 300.0);

    app()->instance(ArcNetworkGateway::class, new class((string) config('lepton.arc.treasury')) extends SettlingArc
    {
        public function __construct(string $treasury)
        {
            parent::__construct($treasury, '0x'.str_repeat('1', 40), $treasury, 5_000000);
        }

        public function rpc(string $method, array $params = []): mixed
        {
            if ($method !== 'eth_getBalance') {
                throw new RuntimeException('gateway timeout');
            }

            return parent::rpc($method, $params);
        }
    });

    app()->instance(WalletGateway::class, new SettlingWallet('0x'.str_repeat('f', 64)));

    expect(fn () => app(StudentTuitionPaymentService::class)->pay($student, $account, 5_000000, '00000000-0000-0000-0000-0000000000a7'))
        ->toThrow(RuntimeException::class, 'could not be confirmed on chain');

    // A provider or RPC that cannot answer is not evidence of wrongdoing, and
    // must never be recorded as a settled payment either way.
    expect(StudentTuitionPayment::query()->count())->toBe(0);

    $attempt = StudentTuitionPaymentAttempt::query()->where('request_key', '00000000-0000-0000-0000-0000000000a7')->first();

    expect($attempt->state)->toBe('awaiting_verification')
        ->and($attempt->settlement_verdict)->toBe('unreadable');
});
