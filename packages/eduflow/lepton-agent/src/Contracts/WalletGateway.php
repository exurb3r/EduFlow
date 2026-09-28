<?php

declare(strict_types=1);

namespace Eduflow\Lepton\Contracts;

use Eduflow\Lepton\DTOs\BalanceResult;
use Eduflow\Lepton\DTOs\TransactionRecord;
use Eduflow\Lepton\DTOs\TransferResult;

interface WalletGateway
{
    /**
     * Transfer USDC (or native Arc USDC) between wallets.
     *
     * @param  int  $amountBaseUnits  Integer minor units (e.g. 100_000000 = 100 USDC).
     * @param  array<string,mixed>  $options  Supported: chain, rpcUrl, token, idempotencyKey, estimate
     */
    public function transfer(string $fromAddress, string $toAddress, int $amountBaseUnits, array $options = []): TransferResult;

    public function balance(string $address, array $options = []): BalanceResult;

    /**
     * @return array<int, TransactionRecord>
     */
    public function transactions(string $address, array $filters = []): array;

    /**
     * Spending policy view. Mainnet-only on Circle side; testnet returns declared-only.
     *
     * @return array<string,mixed>
     */
    public function limits(string $address, array $options = []): array;
}
