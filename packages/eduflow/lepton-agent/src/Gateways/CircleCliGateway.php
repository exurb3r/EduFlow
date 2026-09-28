<?php

declare(strict_types=1);

namespace Eduflow\Lepton\Gateways;

use Eduflow\Lepton\Contracts\ArcNetworkGateway;
use Eduflow\Lepton\Contracts\WalletGateway;
use Eduflow\Lepton\Contracts\X402Gateway;
use Eduflow\Lepton\DTOs\BalanceResult;
use Eduflow\Lepton\DTOs\TransactionRecord;
use Eduflow\Lepton\DTOs\TransferResult;
use Eduflow\Lepton\Support\Amounts;
use Eduflow\Lepton\Support\CliRunner;
use Illuminate\Support\Str;

final class CircleCliGateway implements WalletGateway, X402Gateway
{
    public function __construct(
        private readonly CliRunner $circle,
        private readonly int $usdcDecimals = 6,
        private readonly bool $dryRun = false,
        private readonly ?string $defaultRpcUrl = null,
    ) {}

    public function transfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): TransferResult
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $amount = Amounts::toCliAmount($amountBaseUnits, $this->usdcDecimals);

        $args = ['wallet', 'transfer', $toAddress, '--amount', $amount, '--address', $fromAddress, '--chain', $chain];

        $rpcUrl = $options['rpcUrl'] ?? $this->defaultRpcUrl;
        if (is_string($rpcUrl) && $rpcUrl !== '') {
            array_push($args, '--rpc-url', $rpcUrl);
        }

        if (! empty($options['token'])) {
            array_push($args, '--token', (string) $options['token']);
        }

        array_push($args, '--idempotency-key', (string) ($options['idempotencyKey'] ?? (string) Str::uuid()));

        if ($this->dryRun || ! empty($options['estimate'])) {
            $args[] = '--estimate';
        }

        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson($args);

        $txHash = $out['txHash'] ?? $out['transactionHash'] ?? $out['hash'] ?? $out['id'] ?? null;
        $txHash = is_string($txHash) ? $txHash : (string) ($out['id'] ?? '');

        return new TransferResult(
            txHash: $txHash,
            fromAddress: $fromAddress,
            toAddress: $toAddress,
            amountBaseUnits: $amountBaseUnits,
            chain: $chain,
            isFake: false,
            explorerUrl: app(ArcNetworkGateway::class)->explorerUrl($txHash),
            raw: $out,
        );
    }

    public function balance(string $address, array $options = []): BalanceResult
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['wallet', 'balance', '--address', $address, '--chain', $chain];

        if (! empty($options['token'])) {
            array_push($args, '--token', (string) $options['token']);
        }
        $rpcUrl = $options['rpcUrl'] ?? $this->defaultRpcUrl;
        if (is_string($rpcUrl) && $rpcUrl !== '') {
            array_push($args, '--rpc-url', $rpcUrl);
        }

        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson($args);

        $amountBaseUnits = $this->extractBalance($out);

        return new BalanceResult($address, $amountBaseUnits, $chain, false, $out);
    }

    /**
     * @return array<int, TransactionRecord>
     */
    public function transactions(string $address, array $filters = []): array
    {
        $chain = $filters['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['transaction', 'list', '--address', $address, '--chain', $chain];

        foreach (['operation' => '--operation', 'state' => '--state', 'limit' => '--limit'] as $key => $flag) {
            if (! empty($filters[$key])) {
                array_push($args, $flag, (string) $filters[$key]);
            }
        }

        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson($args);
        $rows = $out['transactions'] ?? $out['data'] ?? (array_is_list($out) ? $out : []);

        $records = [];
        foreach ((array) $rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $records[] = new TransactionRecord(
                id: (string) ($row['id'] ?? $row['txHash'] ?? uniqid()),
                txHash: isset($row['txHash']) ? (string) $row['txHash'] : null,
                state: (string) ($row['state'] ?? $row['status'] ?? 'unknown'),
                operation: isset($row['operation']) ? (string) $row['operation'] : null,
                raw: $row,
            );
        }

        return $records;
    }

    public function limits(string $address, array $options = []): array
    {
        $chain = $options['chain'] ?? 'ARC';
        $args = ['wallet', 'limit', '--address', $address, '--chain', $chain];

        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson($args);

        return $out;
    }

    public function searchServices(string $query): array
    {
        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson(['services', 'search', $query]);

        return $out['services'] ?? (array_is_list($out) ? $out : [$out]);
    }

    public function inspectService(string $url, array $options = []): array
    {
        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson(['services', 'inspect', $url]);

        return $out;
    }

    public function payService(string $url, string $fromAddress, array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['services', 'pay', $url, '--address', $fromAddress, '--chain', $chain];

        foreach (['method' => '--method', 'data' => '--data', 'maxAmount' => '--max-amount', 'timeout' => '--timeout'] as $key => $flag) {
            if (! empty($options[$key])) {
                array_push($args, $flag, (string) $options[$key]);
            }
        }

        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson($args);

        return $out;
    }

    public function gatewayBalance(string $address, array $options = []): array
    {
        $chain = $options['chain'] ?? config('lepton.arc.chain', 'ARC-TESTNET');
        $args = ['gateway', 'balance', '--address', $address, '--chain', $chain];

        /** @var array<string,mixed> $out */
        $out = $this->circle->runJson($args);

        return $out;
    }

    /**
     * @param  array<string,mixed>  $out
     */
    private function extractBalance(array $out): int
    {
        foreach (['balance', 'amount', 'total'] as $key) {
            if (isset($out[$key]) && is_numeric($out[$key])) {
                return Amounts::fromDecimalString((string) $out[$key], $this->usdcDecimals);
            }
        }
        foreach ($out as $row) {
            if (is_array($row) && isset($row['amount']) && is_numeric($row['amount'])) {
                return Amounts::fromDecimalString((string) $row['amount'], $this->usdcDecimals);
            }
            if (is_array($row) && isset($row['balance']) && is_numeric($row['balance'])) {
                return Amounts::fromDecimalString((string) $row['balance'], $this->usdcDecimals);
            }
        }

        return 0;
    }
}
