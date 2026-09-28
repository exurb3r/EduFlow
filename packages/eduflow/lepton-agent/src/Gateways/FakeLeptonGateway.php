<?php

declare(strict_types=1);

namespace Eduflow\Lepton\Gateways;

use Eduflow\Lepton\Contracts\ArcNetworkGateway;
use Eduflow\Lepton\Contracts\WalletGateway;
use Eduflow\Lepton\Contracts\X402Gateway;
use Eduflow\Lepton\DTOs\BalanceResult;
use Eduflow\Lepton\DTOs\TransactionRecord;
use Eduflow\Lepton\DTOs\TransferResult;

/**
 * In-memory driver for Pest tests and offline demo. No CLI, no chain.
 */
final class FakeLeptonGateway implements ArcNetworkGateway, WalletGateway, X402Gateway
{
    /** @var array<string,int> */
    private array $ledger = [];

    /** @var array<int, array<string,mixed>> */
    private array $transactions = [];

    public function seedBalance(string $address, int $amountBaseUnits): void
    {
        $this->ledger[strtolower($address)] = $amountBaseUnits;
    }

    public function transfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): TransferResult
    {
        $from = strtolower($fromAddress);
        $to = strtolower($toAddress);
        $chain = $options['chain'] ?? 'ARC-TESTNET';

        $this->ledger[$from] ??= 0;
        $this->ledger[$to] ??= 0;

        if ($this->ledger[$from] < $amountBaseUnits) {
            throw new \InvalidArgumentException("Insufficient fake balance for [{$fromAddress}].");
        }

        $this->ledger[$from] -= $amountBaseUnits;
        $this->ledger[$to] += $amountBaseUnits;

        $txHash = '0x'.bin2hex(random_bytes(32));
        $this->transactions[] = ['id' => $txHash, 'txHash' => $txHash, 'state' => 'confirmed'];

        return new TransferResult($txHash, $fromAddress, $toAddress, $amountBaseUnits, $chain, true, $txHash, ['fake' => true]);
    }

    public function balance(string $address, array $options = []): BalanceResult
    {
        return new BalanceResult($address, $this->ledger[strtolower($address)] ?? 0, $options['chain'] ?? 'ARC-TESTNET', true, ['fake' => true]);
    }

    public function transactions(string $address, array $filters = []): array
    {
        return array_map(fn (array $t): TransactionRecord => new TransactionRecord($t['id'], $t['txHash'], $t['state'], 'transfer', $t), $this->transactions);
    }

    public function limits(string $address, array $options = []): array
    {
        return ['driver' => 'fake', 'perTx' => null, 'daily' => null, 'declaredOnly' => true];
    }

    public function rpcUrl(): string
    {
        return 'fake://arc-testnet';
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
        return '0x0';
    }

    public function treasuryAddress(): ?string
    {
        return null;
    }

    public function explorerUrl(string $txHash): string
    {
        return 'https://testnet.arcscan.app/tx/'.$txHash;
    }

    public function searchServices(string $query): array
    {
        return [];
    }

    public function inspectService(string $url, array $options = []): array
    {
        return ['url' => $url, 'fake' => true];
    }

    public function payService(string $url, string $fromAddress, array $options = []): array
    {
        return ['url' => $url, 'paid' => false, 'fake' => true];
    }

    public function gatewayBalance(string $address, array $options = []): array
    {
        return ['address' => $address, 'balance' => '0', 'fake' => true];
    }
}
