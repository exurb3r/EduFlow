<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Payments\CircleAgentHttpClient;
use Throwable;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Drivers\CircleRest\CircleRestGateway;
use Yukazakiri\Lepton\Support\Amounts;
use Yukazakiri\Lepton\Support\CliRunner;
use Yukazakiri\Lepton\Support\LeptonCliException;

/**
 * The Circle wallet management operations (`list`, `create`, `fund`, `estimate`, `status`).
 *
 * Uses pure-PHP HTTPS via `CircleAgentHttpClient` (for Circle Agent Wallets) or
 * `CircleRestGateway` (for Circle Developer-Controlled Wallets) in normal runtime,
 * while retaining `CliRunner` as a fallback for test doubles that inject a custom runner.
 */
class CircleAgentWalletCli
{
    /** Agent wallets per blockchain. Hard cap in the Circle Agent Wallets API, no delete verb. */
    public const AGENT_WALLET_LIMIT = 5;

    public function __construct(
        private readonly CliRunner $runner,
        private readonly ?CircleAgentHttpClient $http = null,
        private readonly ?WalletGateway $wallets = null,
    ) {}

    /**
     * Agent wallet addresses on one chain, lowercased for a stable comparison.
     *
     * @return array<int, string>
     */
    public function agentWalletAddresses(string $chain): array
    {
        if ($this->wallets instanceof CircleRestGateway) {
            try {
                $wallets = $this->wallets->listWallets($chain);

                return array_values(array_filter(array_map(
                    static fn (array $w): string => isset($w['address']) && is_string($w['address']) ? strtolower($w['address']) : '',
                    $wallets,
                )));
            } catch (Throwable $e) {
                throw new LeptonCliException(['circle', 'wallet', 'list'], 1, $e->getMessage(), '');
            }
        }

        if ($this->http instanceof CircleAgentHttpClient) {
            try {
                $wallets = $this->http->listWallets($chain);

                return array_values(array_filter(array_map(
                    static fn (array $w): string => isset($w['address']) && is_string($w['address']) ? strtolower($w['address']) : '',
                    $wallets,
                )));
            } catch (Throwable $e) {
                throw new LeptonCliException(['circle', 'wallet', 'list'], 1, $e->getMessage(), '');
            }
        }

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
     * Mint agent wallets on every testnet the account supports.
     *
     * The idempotency key means a retry after a timeout returns the wallets the
     * first attempt already created instead of minting another set.
     *
     * @return array<string, mixed>
     */
    public function createAgentWallet(string $idempotencyKey): array
    {
        if ($this->wallets instanceof CircleRestGateway) {
            try {
                $chain = (string) config('lepton.arc.chain', 'ARC-TESTNET');
                $wallets = $this->wallets->createAgentWallet($idempotencyKey, [$chain]);

                return ['data' => ['wallets' => $wallets]];
            } catch (Throwable $e) {
                throw new LeptonCliException(['circle', 'wallet', 'create'], 1, $e->getMessage(), '');
            }
        }

        if ($this->http instanceof CircleAgentHttpClient) {
            try {
                $chain = (string) config('lepton.arc.chain', 'ARC-TESTNET');

                return $this->http->createAgentWallet($idempotencyKey, [$chain]);
            } catch (Throwable $e) {
                throw new LeptonCliException(['circle', 'wallet', 'create'], 1, $e->getMessage(), '');
            }
        }

        return $this->runner->runJson([
            'wallet', 'create',
            '--testnet',
            '--idempotency-key', $idempotencyKey,
        ]);
    }

    /**
     * Drip the testnet faucet into a wallet the account owns.
     *
     * @return array<string, mixed>|string
     */
    public function fundWallet(string $address, string $chain): array|string
    {
        if ($this->wallets instanceof CircleRestGateway) {
            try {
                return ['data' => $this->wallets->fundTestnetWallet($address, $chain)];
            } catch (Throwable $e) {
                throw new LeptonCliException(['circle', 'wallet', 'fund'], 1, $e->getMessage(), '');
            }
        }

        if ($this->http instanceof CircleAgentHttpClient) {
            try {
                return $this->http->fundWallet($address, $chain);
            } catch (Throwable $e) {
                throw new LeptonCliException(['circle', 'wallet', 'fund'], 1, $e->getMessage(), '');
            }
        }

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
        if ($this->wallets instanceof CircleRestGateway) {
            try {
                $fee = $this->wallets->estimateFee($toAddress, $amountBaseUnits, [
                    'from' => $fromAddress,
                    'chain' => $chain,
                ]);

                return [
                    'data' => [
                        'estimatedFee' => [
                            'networkFee' => $fee['medium']['networkFee'] ?? $fee['high']['networkFee'] ?? '0',
                            'gasLimit' => $fee['medium']['gasLimit'] ?? null,
                            'maxFee' => $fee['medium']['maxFee'] ?? null,
                        ],
                    ],
                ];
            } catch (Throwable $e) {
                throw new LeptonCliException(['circle', 'wallet', 'transfer', '--estimate'], 1, $e->getMessage(), '');
            }
        }

        if ($this->http instanceof CircleAgentHttpClient) {
            try {
                return $this->http->estimateTransferFee(
                    $fromAddress,
                    $toAddress,
                    Amounts::toCliAmount($amountBaseUnits, 6),
                    $chain,
                );
            } catch (Throwable $e) {
                throw new LeptonCliException(['circle', 'wallet', 'transfer', '--estimate'], 1, $e->getMessage(), '');
            }
        }

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
        if ($this->wallets instanceof CircleRestGateway) {
            return $this->wallets->isAuthenticatedFor((string) config('lepton.arc.chain', 'ARC-TESTNET'));
        }

        if ($this->http instanceof CircleAgentHttpClient) {
            return $this->http->isAuthenticatedFor((string) config('lepton.arc.chain', 'ARC-TESTNET'));
        }

        try {
            $this->runner->runJson(['wallet', 'status']);

            return true;
        } catch (LeptonCliException) {
            return false;
        }
    }
}
