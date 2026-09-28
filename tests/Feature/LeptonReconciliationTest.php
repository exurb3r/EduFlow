<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\LeptonReconciliationService;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\TransactionRecord;
use Yukazakiri\Lepton\DTOs\TransferResult;

/**
 * Stubs a chain that knows about exactly one hash. Every other hash is absent,
 * which is the situation that let fabricated receipts pass unnoticed.
 */
function chainKnowing(string $knownHash, bool $reachable = true): ArcNetworkGateway
{
    return new class($knownHash, $reachable) implements ArcNetworkGateway
    {
        public function __construct(private readonly string $knownHash, private readonly bool $reachable) {}

        public function rpcUrl(): string
        {
            return 'https://rpc.testnet.arc-node.thecanteenapp.com/v1/test';
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
            return '0x3d667a7';
        }

        public function treasuryAddress(): ?string
        {
            return null;
        }

        public function explorerUrl(string $txHash): string
        {
            return 'https://testnet.arcscan.app/tx/'.$txHash;
        }

        public function addressExplorerUrl(string $address): string
        {
            return 'https://testnet.arcscan.app/address/'.$address;
        }

        public function rpc(string $method, array $params = []): mixed
        {
            if (! $this->reachable) {
                throw new RuntimeException('offline');
            }

            if ($method === 'eth_getTransactionByHash') {
                return $params[0] === $this->knownHash
                    ? ['blockNumber' => '0x3d667a7', 'hash' => $this->knownHash]
                    : null;
            }

            return null;
        }
    };
}

/**
 * Agent wallet history containing only the hashes it is told about.
 *
 * @param  list<string>  $knownHashes
 */
function agentWalletKnowing(array $knownHashes = []): WalletGateway
{
    return new class($knownHashes) implements WalletGateway
    {
        /** @param list<string> $knownHashes */
        public function __construct(private readonly array $knownHashes) {}

        public function transfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): TransferResult
        {
            throw new RuntimeException('not used in reconciliation tests');
        }

        public function balance(string $address, array $options = []): BalanceResult
        {
            return new BalanceResult($address, 0, 'ARC-TESTNET', true, []);
        }

        public function transactions(string $address, array $filters = []): array
        {
            return array_map(
                fn (string $hash): TransactionRecord => new TransactionRecord('id-'.$hash, $hash, 'COMPLETE', 'TRANSFER', []),
                $this->knownHashes
            );
        }

        public function limits(string $address, array $options = []): array
        {
            return [];
        }
    };
}

beforeEach(function (): void {
    config(['lepton.default' => 'fake', 'lepton.arc.chain' => 'ARC-TESTNET']);

    $this->org = Organization::create([
        'name' => 'Reconcile Academy',
        'currency' => 'USDC',
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    $this->wallet = Wallet::create([
        'organization_id' => $this->org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0xabc',
        'balance' => 1000.00,
        'status' => 'active',
    ]);

    $this->realHash = '0x'.str_repeat('a', 64);
    $this->fakeHash = '0x'.str_repeat('b', 64);
});

function settleAgainst(ArcNetworkGateway $chain, WalletGateway $wallets): LeptonReconciliationService
{
    return new LeptonReconciliationService($chain, $wallets);
}

function payment(Organization $org, Wallet $wallet, ?string $hash, array $metadata = [], ?Invoice $for = null): Transaction
{
    return Transaction::create([
        'organization_id' => $org->id,
        'wallet_id' => $wallet->id,
        'type' => TransactionType::VENDOR_PAYMENT,
        'recipient_address' => '0xvendor',
        'amount' => 450.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => $hash,
        'reference_type' => $for !== null ? Invoice::class : null,
        'reference_id' => $for?->id,
        'metadata' => $metadata,
    ]);
}

test('a hash present on the chain verifies with its block', function (): void {
    $tx = payment($this->org, $this->wallet, $this->realHash, ['is_fake' => false]);

    $result = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verify($tx);

    expect($result['verdict'])->toBe('verified')
        ->and($result['block'])->toBe((int) hexdec('3d667a7'))
        ->and($result['chain_source'])->toBe('rpc');
});

test('a hash absent from a reachable chain is reported as fabricated', function (): void {
    $tx = payment($this->org, $this->wallet, $this->fakeHash, ['is_fake' => true]);

    $result = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verify($tx);

    expect($result['verdict'])->toBe('fabricated')
        ->and($result['reason'])->toContain('never settled');
});

test('a transaction with no hash is a ledger entry, not a failure', function (): void {
    $tx = payment($this->org, $this->wallet, null, ['simulated_inbound' => true]);

    $result = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verify($tx);

    expect($result['verdict'])->toBe('ledger_only')
        ->and($result['reason'])->toContain('not a chain transfer');
});

test('when rpc is down the circle wallet history is used as the fallback', function (): void {
    $tx = payment($this->org, $this->wallet, $this->fakeHash);

    $result = settleAgainst(chainKnowing('', reachable: false), agentWalletKnowing([$this->fakeHash]))->verify($tx);

    expect($result['verdict'])->toBe('verified')
        ->and($result['chain_source'])->toBe('circle');
});

test('when rpc is down and circle does not know the hash it is still fabricated', function (): void {
    $tx = payment($this->org, $this->wallet, $this->fakeHash);

    $result = settleAgainst(chainKnowing('', reachable: false), agentWalletKnowing())->verify($tx);

    expect($result['verdict'])->toBe('fabricated')
        ->and($result['chain_source'])->toBe('circle');
});

test('verifyAll summarises the ledger honestly', function (): void {
    payment($this->org, $this->wallet, $this->realHash);
    payment($this->org, $this->wallet, $this->fakeHash);
    payment($this->org, $this->wallet, null, ['simulated_inbound' => true]);

    $report = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verifyAll();

    expect($report['total'])->toBe(3)
        ->and($report['verified'])->toBe(1)
        ->and($report['fabricated'])->toBe(1)
        ->and($report['ledger_only'])->toBe(1)
        ->and($report['verdict'])->toBe('discrepancies');
});

test('verifyAll reports clean when every receipt is genuine', function (): void {
    payment($this->org, $this->wallet, $this->realHash);

    $report = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verifyAll();

    expect($report['verdict'])->toBe('clean')
        ->and($report['fabricated'])->toBe(0);
});

test('invoice settlement is unsupported when its payment never happened', function (): void {
    $vendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'Cloud Co',
        'wallet_address' => '0xvendor',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);

    $invoice = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $vendor->id,
        'reference' => 'INV-RECON-450',
        'amount' => 450.00,
        'due_date' => today(),
        'status' => 'auto_paid',
    ]);

    // The invoice is marked auto_paid, but its payment never settled on chain.
    payment($this->org, $this->wallet, $this->fakeHash, [], $invoice);

    $result = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())
        ->verifyInvoiceSettlement($invoice);

    expect($result['settlement_verdict'])->toBe('unsupported')
        ->and($result['evidence'])->toHaveCount(1);
});

test('invoice settlement is verified once the payment is on chain', function (): void {
    $vendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'Cloud Co',
        'wallet_address' => '0xvendor',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);

    $invoice = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $vendor->id,
        'reference' => 'INV-RECON-OK',
        'amount' => 450.00,
        'due_date' => today(),
        'status' => 'auto_paid',
    ]);

    payment($this->org, $this->wallet, $this->realHash, ['is_fake' => false], $invoice);

    $result = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verifyInvoiceSettlement($invoice);

    expect($result['settlement_verdict'])->toBe('verified');
});

test('an invoice with no payment at all is unsettled', function (): void {
    $vendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'Cloud Co',
        'wallet_address' => '0xvendor',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);

    $invoice = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $vendor->id,
        'reference' => 'INV-RECON-NONE',
        'amount' => 450.00,
        'due_date' => today(),
        'status' => 'pending',
    ]);

    $result = settleAgainst(chainKnowing($this->realHash), agentWalletKnowing())->verifyInvoiceSettlement($invoice);

    expect($result['settlement_verdict'])->toBe('unsettled');
});
