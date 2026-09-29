<?php

declare(strict_types=1);

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Filament\Resources\Transactions\Tables\TransactionsTable;
use App\Filament\Widgets\LeptonNetworkWidget;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Services\LeptonTreasuryService;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

beforeEach(function (): void {
    config([
        'lepton.default' => 'fake',
        'lepton.arc.chain' => 'ARC-TESTNET',
        'lepton.arc.chain_id' => 5042002,
        'lepton.arc.treasury' => '0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E',
    ]);

    $this->org = Organization::create([
        'name' => 'Chain Truth Academy',
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
        'address' => '0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E',
        'balance' => 25420.00,
        'status' => 'active',
    ]);
});

test('status reports chain identity and explorer link for the configured treasury', function (): void {
    $status = app(LeptonTreasuryService::class)->status($this->wallet);

    expect($status['chain'])->toBe('ARC-TESTNET')
        ->and($status['chain_id'])->toBe(5042002)
        ->and($status['treasury_address'])->toBe('0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E')
        ->and($status['address_url'])->toBe('https://testnet.arcscan.app/address/0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E');
});

test('status never throws when the chain is unreachable and explains why', function (): void {
    $this->app->instance(ArcNetworkGateway::class, new class implements ArcNetworkGateway
    {
        public function rpcUrl(): string
        {
            throw new RuntimeException('no rpc');
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
            throw new RuntimeException('offline');
        }

        public function treasuryAddress(): ?string
        {
            return '0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E';
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
            throw new RuntimeException('offline');
        }
    });

    $status = app(LeptonTreasuryService::class)->status($this->wallet);

    expect($status['live_available'])->toBeFalse()
        ->and($status['onchain_balance'])->toBeNull()
        ->and($status['block'])->toBeNull()
        ->and($status['error'])->toContain('circle wallet login');
});

test('status surfaces ledger drift instead of hiding it', function (): void {
    $this->app->instance(ArcNetworkGateway::class, new class implements ArcNetworkGateway
    {
        public function rpcUrl(): string
        {
            return 'https://rpc.testnet.arc-node.thecanteenapp.com/v1/abc';
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
            return '0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E';
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
            // 5 native USDC at 18 decimals.
            return $method === 'eth_getBalance' ? '0x4563918244f40000' : null;
        }
    });

    $status = app(LeptonTreasuryService::class)->status($this->wallet);

    // Raw numerics, not pre-formatted strings: (float) "24,470.00" parses as 24.0.
    expect($status['live_available'])->toBeTrue()
        ->and($status['onchain_balance'])->toBe(5.0)
        ->and($status['ledger_balance'])->toBe(25420.0)
        ->and($status['block'])->toBe((int) hexdec('3d667a7'))
        ->and($status['rpc_host'])->toBe('rpc.testnet.arc-node.thecanteenapp.com')
        ->and($status['in_sync'])->toBeFalse()
        ->and($status['drift'])->toBe(25415.0);
});

test('syncBalance writes the live chain figure over the ledger', function (): void {
    $this->app->instance(ArcNetworkGateway::class, new class implements ArcNetworkGateway
    {
        public function rpcUrl(): string
        {
            return 'https://rpc.testnet.arc-node.thecanteenapp.com/v1/abc';
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
            return '0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E';
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
            return $method === 'eth_getBalance' ? '0x4563918244f40000' : null;
        }
    });

    $live = app(LeptonTreasuryService::class)->syncBalance($this->wallet);

    expect($live)->toBe(5.0)
        ->and($this->wallet->fresh()->balance)->toBe(5.0);
});

test('syncBalance is a no-op when the chain cannot be read', function (): void {
    // A node that answers nothing usable for a balance read.
    $this->app->instance(ArcNetworkGateway::class, new class implements ArcNetworkGateway
    {
        public function rpcUrl(): string
        {
            throw new RuntimeException('offline');
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
            throw new RuntimeException('offline');
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
            return null;
        }
    });

    expect(app(LeptonTreasuryService::class)->syncBalance($this->wallet))->toBeNull()
        ->and($this->wallet->fresh()->balance)->toBe(25420.00);
});

test('adoptConfiguredWallet repoints a placeholder treasury at the agent wallet', function (): void {
    $placeholder = Wallet::create([
        'organization_id' => $this->org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0x3a9B97F3dF02B418E97E1C7D6B9c7E67eB3682cA',
        'balance' => 1000.00,
        'status' => 'active',
    ]);

    $changed = app(LeptonTreasuryService::class)->adoptConfiguredWallet($placeholder);

    expect($changed)->toBeTrue()
        ->and($placeholder->fresh()->address)->toBe('0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E')
        ->and($placeholder->fresh()->balance)->toBe(1000.00);

    // Idempotent: already on the agent wallet.
    expect(app(LeptonTreasuryService::class)->adoptConfiguredWallet($placeholder->fresh()))->toBeFalse();
});

test('primaryWallet prefers the configured lepton treasury over stale rows', function (): void {
    Wallet::create([
        'organization_id' => $this->org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0x0000000000000000000000000000000000000001',
        'balance' => 5.00,
        'status' => 'active',
    ]);

    expect($this->org->primaryWallet()?->address)->toBe('0x7d41e0AA33A4850E20ECa993dF6F0403d6527C6E');
});

test('primaryWallet ignores inactive wallets', function (): void {
    $this->wallet->update(['status' => 'suspended']);
    config(['lepton.arc.treasury' => null]);

    expect($this->org->primaryWallet())->toBeNull();
});

test('a stored hash is labelled unverified until proven, never on-chain', function (): void {
    $tx = Transaction::create([
        'organization_id' => $this->org->id,
        'wallet_id' => $this->wallet->id,
        'type' => TransactionType::VENDOR_PAYMENT,
        'recipient_address' => '0xvendor',
        'amount' => 450.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => '0x'.str_repeat('a', 64),
        'metadata' => ['gateway' => 'circle-cli', 'is_fake' => false],
    ]);

    // The database cannot vouch for itself: a hash alone must not read as settled.
    expect(TransactionsTable::settlementLabel($tx))->toBe('Unverified')
        ->and(TransactionsTable::settlementTooltip($tx))->toContain('a claim, not proof');

    $tx->update(['metadata' => array_merge($tx->metadata, [
        'reconciliation' => 'failed',
        'reconciliation_note' => 'Hash absent from chain.',
    ])]);

    expect(TransactionsTable::settlementLabel($tx->fresh()))->toBe('Never settled')
        ->and(TransactionsTable::settlementColor($tx->fresh()))->toBe('danger')
        ->and($tx->fresh()->isReconciledFailed())->toBeTrue();

    $tx->update(['metadata' => array_merge($tx->metadata, ['reconciliation' => 'verified'])]);

    expect($tx->fresh()->isReconciledFailed())->toBeFalse();
});

test('transaction provenance distinguishes on-chain, simulated and ledger-only', function (): void {
    $onchain = Transaction::create([
        'organization_id' => $this->org->id,
        'wallet_id' => $this->wallet->id,
        'type' => TransactionType::VENDOR_PAYMENT,
        'recipient_address' => '0xvendor',
        'amount' => 450.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => '0x'.str_repeat('a', 64),
        'metadata' => ['gateway' => 'circle-cli', 'is_fake' => false, 'explorer_url' => 'https://testnet.arcscan.app/tx/0xabc'],
    ]);

    $simulated = Transaction::create([
        'organization_id' => $this->org->id,
        'wallet_id' => $this->wallet->id,
        'type' => TransactionType::VENDOR_PAYMENT,
        'recipient_address' => '0xvendor',
        'amount' => 10.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => '0x'.str_repeat('b', 64),
        'metadata' => ['gateway' => 'fake', 'is_fake' => true],
    ]);

    $ledger = Transaction::create([
        'organization_id' => $this->org->id,
        'wallet_id' => $this->wallet->id,
        'type' => TransactionType::TUITION_REVENUE,
        'recipient_address' => $this->wallet->address,
        'amount' => 10000.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => null,
        'metadata' => ['simulated_inbound' => true],
    ]);

    expect(TransactionsTable::settlementLabel($onchain))->toBe('Unverified')
        ->and(TransactionsTable::settlementColor($onchain))->toBe('info')
        ->and(TransactionsTable::explorerUrl($onchain))->toBe('https://testnet.arcscan.app/tx/0xabc');

    expect(TransactionsTable::settlementLabel($simulated))->toBe('Simulated')
        ->and(TransactionsTable::settlementColor($simulated))->toBe('warning')
        ->and(TransactionsTable::settlementTooltip($simulated))->toContain('fake Lepton driver');

    expect(TransactionsTable::settlementLabel($ledger))->toBe('Ledger only')
        ->and(TransactionsTable::settlementColor($ledger))->toBe('gray')
        ->and(TransactionsTable::explorerUrl($ledger))->toBeNull();
});

test('explorer url falls back to the live gateway when metadata is absent', function (): void {
    $tx = Transaction::create([
        'organization_id' => $this->org->id,
        'wallet_id' => $this->wallet->id,
        'type' => TransactionType::VENDOR_PAYMENT,
        'recipient_address' => '0xvendor',
        'amount' => 1.00,
        'status' => TransactionStatus::CONFIRMED,
        'provider_tx_hash' => '0x'.str_repeat('c', 64),
        'metadata' => null,
    ]);

    expect(TransactionsTable::explorerUrl($tx))
        ->toBe('https://testnet.arcscan.app/tx/0x'.str_repeat('c', 64));
});

test('lepton network widget renders chain stats for the treasury', function (): void {
    $widget = new class extends LeptonNetworkWidget
    {
        /**
         * @return array<int, Stat>
         */
        public function exposedStats(): array
        {
            return $this->getStats();
        }
    };

    $labels = array_map(fn (Stat $stat): string => (string) $stat->getLabel(), $widget->exposedStats());

    expect($labels)->toContain('Arc Settlement Network')
        ->and($labels)->toContain('On-Chain Treasury')
        ->and($labels)->toContain('Agent Wallet')
        ->and($labels)->toContain('Lepton Driver');
});
