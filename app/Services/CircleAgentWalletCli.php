<?php

declare(strict_types=1);

namespace App\Services;

use Yukazakiri\Lepton\Support\Amounts;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonCliException;

/**
 * The Circle CLI verbs Lepton does not wrap.
 *
 * `wallet create` and `wallet fund` are outside the package's contract, so they
 * are called through the same argv runner the package uses rather than a shell
 * string. It is resolved from the container so tests can substitute a fake and
 * never shell out.
 */
class CircleAgentWalletCli
{
    /** Agent wallets per blockchain. Hard cap in the Circle CLI, no delete verb. */
    public const AGENT_WALLET_LIMIT = 5;

    public function __construct(
        private readonly CliRunner $runner,
    ) {}

    /**
     * Agent wallet addresses on one chain, lowercased for a stable comparison.
     *
     * @return array<int, string>
     */
    public function agentWalletAddresses(string $chain): array
    {
        $result = $this->runner->runJson(['wallet', 'list', '--type', 'agent', '--chain', $chain]);

        $wallets = is_array($result['data']['wallets'] ?? null) ? $result['data']['wallets'] : [];

        $addresses = [];

        foreach ($wallets as $wallet) {
            $address = is_array($wallet) ? ($wallet['address'] ?? null) : null;

            if (is_string($address) && $address !== '') {
                $addresses[] = strtolower($address);
            }
        }

        return $addresses;
    }

    public function remainingAgentWalletSlots(string $chain): int
    {
        return max(0, self::AGENT_WALLET_LIMIT - count($this->agentWalletAddresses($chain)));
    }

    /**
     * Mint agent wallets on every testnet the CLI supports.
     *
     * The idempotency key means a retry after a timeout returns the wallets the
     * first attempt already created instead of minting another set.
     *
     * @return array<string, mixed>
     */
    public function createAgentWallet(string $idempotencyKey): array
    {
        return $this->runner->runJson([
            'wallet', 'create',
            '--testnet',
            '--idempotency-key', $idempotencyKey,
        ]);
    }

    /**
     * Drip the testnet faucet into a wallet the CLI itself owns.
     *
     * @return array<string, mixed>|string
     */
    public function fundWallet(string $address, string $chain): array|string
    {
        return $this->runner->runJson([
            'wallet', 'fund',
            '--address', $address,
            '--chain', $chain,
        ]);
    }

    /**
     * Quote the network fee for a transfer without broadcasting it.
     *
     * Arc pays gas in native USDC, so the fee comes out of the sender's wallet
     * on top of the amount. Quoting first is free and lets the payer see the
     * real total before spending anything.
     *
     * @return array<string, mixed>
     */
    public function estimateTransfer(string $fromAddress, string $toAddress, int $amountBaseUnits, string $chain): array
    {
        $result = $this->runner->runJson([
            'wallet', 'transfer', $toAddress,
            '--amount', Amounts::toCliAmount($amountBaseUnits, 6),
            '--address', $fromAddress,
            '--chain', $chain,
            '--estimate',
        ]);

        return is_array($result) ? $result : [];
    }

    public function isAvailable(): bool
    {
        try {
            $this->runner->runJson(['wallet', 'status']);

            return true;
        } catch (LeptonCliException) {
            return false;
        }
    }
}
