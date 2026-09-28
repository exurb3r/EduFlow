<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\InvoiceSettlement;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\DTOs\TransferResult;

/**
 * Invoices are database rows, so a "paid" status proves nothing. These tests
 * pin the rule that a paid invoice only reads as settled when a real receipt
 * backs it.
 */
beforeEach(function (): void {
    config(['lepton.default' => 'fake', 'lepton.arc.chain' => 'ARC-TESTNET']);
    cache()->clear();

    $this->org = Organization::create([
        'name' => 'Invoice Proof Academy',
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
        'address' => '0xtreasury',
        'balance' => 25420.00,
        'status' => 'active',
    ]);

    $vendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'Cloud Co',
        'wallet_address' => '0xvendor',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);

    $this->realHash = '0x'.str_repeat('a', 64);
    $this->fakeHash = '0x'.str_repeat('b', 64);
});

function invoiceFor(Organization $org, string $reference, string $status): Invoice
{
    return Invoice::create([
        'organization_id' => $org->id,
        'vendor_id' => $org->vendors()->first()->id,
        'reference' => $reference,
        'amount' => 450.00,
        'due_date' => today(),
        'status' => $status,
    ]);
}

function settlementKnowing(string $knownHash, bool $reachable = true): InvoiceSettlement
{
    $chain = new class($knownHash, $reachable) implements ArcNetworkGateway
    {
        public function __construct(private readonly string $known, private readonly bool $reachable) {}

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
            return '0x1';
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

            return $method === 'eth_getTransactionByHash' && $params[0] === $this->known ? ['blockNumber' => '0x1'] : null;
        }
    };

    $wallets = new class implements WalletGateway
    {
        public function transfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): TransferResult
        {
            throw new RuntimeException('unused');
        }

        public function balance(string $address, array $options = []): BalanceResult
        {
            return new BalanceResult($address, 0, 'ARC-TESTNET', true, []);
        }

        public function transactions(string $address, array $filters = []): array
        {
            return [];
        }

        public function limits(string $address, array $options = []): array
        {
            return [];
        }
    };

    return new InvoiceSettlement($chain, $wallets);
}

function payInvoice(Organization $org, Wallet $wallet, Invoice $invoice, ?string $hash): void
{
    Transaction::create([
        'organization_id' => $org->id,
        'wallet_id' => $wallet->id,
        'type' => TransactionType::VENDOR_PAYMENT,
        'recipient_address' => '0xvendor',
        'amount' => 450.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => $hash,
        'reference_type' => Invoice::class,
        'reference_id' => $invoice->id,
        'metadata' => ['is_fake' => false],
    ]);
}

test('a paid invoice backed by an on-chain receipt reads as verified', function (): void {
    $invoice = invoiceFor($this->org, 'INV-PROOF-1', 'auto_paid');
    payInvoice($this->org, $this->wallet, $invoice, $this->realHash);

    expect(settlementKnowing($this->realHash)->verdict($invoice))->toBe('verified');
});

test('a paid invoice whose receipt is absent from the chain is unsupported', function (): void {
    $invoice = invoiceFor($this->org, 'INV-PROOF-2', 'auto_paid');
    payInvoice($this->org, $this->wallet, $invoice, $this->fakeHash);

    $service = settlementKnowing($this->realHash);

    expect($service->verdict($invoice))->toBe('unsupported')
        ->and($service->explain($invoice))->toContain('cannot be proven');
});

test('a paid invoice with no linked payment is unsettled', function (): void {
    $invoice = invoiceFor($this->org, 'INV-PROOF-3', 'paid');

    $service = settlementKnowing($this->realHash);

    expect($service->verdict($invoice))->toBe('unsettled')
        ->and($service->explain($invoice))->toContain('no settlement transaction');
});

test('an unpaid invoice is never asked to prove settlement', function (): void {
    $invoice = invoiceFor($this->org, 'INV-PROOF-4', 'pending');

    expect(settlementKnowing($this->realHash)->verdict($invoice))->toBe('not_settled');
});

test('verdicts reach the invoice table column helper', function (): void {
    config(['lepton.default' => 'circle']);
    cache()->clear();

    $invoice = invoiceFor($this->org, 'INV-PROOF-5', 'auto_paid');
    payInvoice($this->org, $this->wallet, $invoice, $this->realHash);

    // The static helper resolves through the container, so the table can call it.
    expect(InvoiceSettlement::verdictFor($invoice))->toBeIn(['verified', 'unverifiable', 'unsupported', 'unsettled'])
        ->and(InvoiceSettlement::explanationFor($invoice))->not->toBeEmpty();
});
