<?php

declare(strict_types=1);

namespace Eduflow\Lepton;

use Eduflow\Lepton\Contracts\ArcNetworkGateway;
use Eduflow\Lepton\Contracts\WalletGateway;
use Eduflow\Lepton\Contracts\X402Gateway;
use Eduflow\Lepton\DTOs\BalanceResult;
use Eduflow\Lepton\DTOs\TransferResult;
use Eduflow\Lepton\Support\Amounts;
use InvalidArgumentException;

final class LeptonManager
{
    public function __construct(
        private readonly WalletGateway $wallets,
        private readonly ArcNetworkGateway $arc,
        private readonly X402Gateway $x402,
    ) {}

    public function wallets(): WalletGateway
    {
        return $this->wallets;
    }

    public function arc(): ArcNetworkGateway
    {
        return $this->arc;
    }

    public function services(): X402Gateway
    {
        return $this->x402;
    }

    /**
     * Convenience: transfer decimal string like "450.00" without floats.
     */
    public function transferDecimal(string $from, string $to, string $decimalAmount, array $options = []): TransferResult
    {
        $decimals = (int) config('lepton.usdc_decimals', 6);

        return $this->wallets->transfer($from, $to, Amounts::fromDecimalString($decimalAmount, $decimals), $options);
    }

    public function balance(string $address, array $options = []): BalanceResult
    {
        return $this->wallets->balance($address, $options);
    }

    /**
     * @return array<string,mixed>
     */
    public function status(string $address): array
    {
        if ($address === '') {
            throw new InvalidArgumentException('Lepton status requires a wallet address.');
        }

        $chain = config('lepton.arc.chain', 'ARC-TESTNET');

        return [
            'chain' => $chain,
            'chainId' => $this->arc->chainId(),
            'rpcUrl' => $this->arc->rpcUrl(),
            'block' => $this->arc->blockNumber(),
            'balance' => $this->wallets->balance($address, ['chain' => $chain])->amountBaseUnits,
            'limits' => $this->wallets->limits($address),
        ];
    }
}
