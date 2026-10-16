<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\Gateways\CircleRestGateway;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * Circle agent wallets, over the Circle W3S HTTP API (with CLI fallback).
 *
 * Supports both Circle Agent Wallets (via `CircleAgentHttpClient` reading the
 * operator's W3S session directly over HTTPS) and Circle Developer-Controlled
 * Wallets (`CircleRestGateway` from `yukazakiri/lepton-agent`), eliminating the
 * need to spawn the Node `circle` CLI binary on every quote, transfer, and
 * resolution poll.
 *
 * `submit()` returns a Circle transaction UUID (`TransportAcceptance`), never
 * an unverified hash, and `resolve()` is the only place an on-chain transaction
 * hash may enter the system before `ArcSettlementVerifier` checks the receipt.
 */
final readonly class CircleAgentWalletTransport implements FeeBoundedPaymentTransport
{
    public function __construct(
        private string $binary,
        private int $timeout = 120,
        private int $usdcDecimals = 6,
        private ?CircleAgentHttpClient $http = null,
        private ?WalletGateway $walletGateway = null,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            binary: (string) config('lepton.circle.bin', 'circle'),
            timeout: (int) config('lepton.circle.timeout', 120),
            usdcDecimals: (int) config('lepton.usdc_decimals', 6),
            http: app()->bound(CircleAgentHttpClient::class)
                ? app(CircleAgentHttpClient::class)
                : new CircleAgentHttpClient((int) config('lepton.circle.timeout', 30)),
            walletGateway: app()->bound(WalletGateway::class) ? app(WalletGateway::class) : null,
        );
    }

    public function submit(array $request): TransportAcceptance
    {
        // A ceiling this rail cannot apply must be refused outright rather than
        // silently dropped. Previously this only fired for a signer-enforced rail
        // — a condition that could never hold here — so the cap travelled to the
        // CLI and was discarded, leaving a caller believing it had been honoured.
        if (array_key_exists('max_fee_base_units', $request)) {
            throw new RuntimeException('Circle CLI sets no transaction fee ceiling; a requested cap cannot be honoured and is refused rather than ignored.');
        }

        if ($this->walletGateway instanceof CircleRestGateway) {
            $result = $this->walletGateway->transfer(
                (string) $request['from'],
                (string) $request['to'],
                (int) $request['amount_base_units'],
                [
                    'chain' => (string) $request['chain'],
                    'idempotencyKey' => (string) $request['idempotency_key'],
                ],
            );

            $candidate = trim((string) ($result->handle ?? $result->raw['id'] ?? $result->raw['data']['id'] ?? ''));
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $candidate) !== 1) {
                throw new RuntimeException(
                    'Circle REST API returned no transaction identifier for transfer; submission state is unknown, not settled.'
                );
            }

            return new TransportAcceptance(handle: strtolower($candidate), raw: ['raw' => $result->raw]);
        }

        if ($this->http instanceof CircleAgentHttpClient) {
            $handle = $this->http->submitTransfer(
                fromAddress: (string) $request['from'],
                toAddress: (string) $request['to'],
                amountDecimal: Amounts::toCliAmount((int) $request['amount_base_units'], $this->usdcDecimals),
                chain: (string) $request['chain'],
                idempotencyKey: (string) $request['idempotency_key'],
            );
        } else {
            $handle = $this->run([
                'wallet', 'transfer', $request['to'],
                '--amount', Amounts::toCliAmount((int) $request['amount_base_units'], $this->usdcDecimals),
                '--address', $request['from'],
                '--chain', $request['chain'],
                '--idempotency-key', $request['idempotency_key'],
                '--quiet',
            ]);
        }

        // A handle is an opaque provider identifier. Accept only something that
        // is shaped like one, because whatever else the rail returned is not
        // evidence that a transfer exists.
        $candidate = is_array($handle)
            ? trim((string) ($handle['id'] ?? $handle['transactionId'] ?? $handle['transaction_id'] ?? ''))
            : trim((string) $handle);

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $candidate) !== 1) {
            throw new RuntimeException(
                'Circle rail returned no transaction identifier for an agent-wallet transfer; the submission state is unknown, not settled.'
            );
        }

        return new TransportAcceptance(handle: strtolower($candidate), raw: ['raw' => $handle]);
    }

    /**
     * The Circle agent wallet rail is relayer-sponsored.
     *
     * Verified on three real ARC-TESTNET receipts rather than inferred from the
     * EVM rule. The payer's balance moved by exactly the principal and not one
     * wei more, while the receipt still carried a non-zero `gasUsed ×
     * effectiveGasPrice`. That fee was paid by Circle: a Transfer log shows the
     * relay contract reimbursing the relayer.
     *
     * So the payer's debit here is the principal alone. Callers must therefore
     * test affordability against the amount, not against amount + fee ceiling.
     */
    public function feeGuarantee(): FeeGuarantee
    {
        return FeeGuarantee::RelayerSponsored;
    }

    public function supportsFeeCeiling(): bool
    {
        return $this->feeGuarantee() === FeeGuarantee::SignerEnforced;
    }

    /**
     * Fee preflight through the rail itself over HTTPS.
     *
     * `estimateFee` never broadcasts, which is exactly what a preflight must be.
     * The response is returned raw rather than parsed into an amount the code
     * trusts: the only fee this system will later enforce is the one the
     * settlement receipt proves, so a preflight number is informational and is
     * recorded as evidence, never as authority.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>|null
     */
    public function estimate(array $request): ?array
    {
        $cacheKey = 'circle_wallet_fee_estimate_'.md5((string) ($request['chain'] ?? ''));

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $quoted = null;

        if ($this->http instanceof CircleAgentHttpClient) {
            try {
                $quoted = $this->http->estimateTransferFee(
                    fromAddress: (string) $request['from'],
                    toAddress: (string) $request['to'],
                    amountDecimal: Amounts::toCliAmount((int) $request['amount_base_units'], $this->usdcDecimals),
                    chain: (string) $request['chain'],
                );
            } catch (Throwable $e) {
                $fallback = Cache::get($cacheKey.'_last_known');
                if (is_array($fallback)) {
                    return $fallback;
                }

                throw new RuntimeException('Circle HTTP fee estimate failed: '.$e->getMessage(), $e->getCode(), previous: $e);
            }
        } else {
            $result = $this->run([
                'wallet', 'transfer', $request['to'],
                '--amount', Amounts::toCliAmount((int) $request['amount_base_units'], $this->usdcDecimals),
                '--address', $request['from'],
                '--chain', $request['chain'],
                '--estimate',
                '--output', 'json',
            ]);
            if (is_array($result)) {
                $quoted = $result;
            }
        }

        if (is_array($quoted)) {
            Cache::put($cacheKey, $quoted, 3600);
            Cache::forever($cacheKey.'_last_known', $quoted);

            return $quoted;
        }

        $fallback = Cache::get($cacheKey.'_last_known');
        if (is_array($fallback)) {
            return $fallback;
        }

        return null;
    }

    public function resolve(string $handle, array $request): TransportResolution
    {
        if ($this->walletGateway instanceof CircleRestGateway) {
            $res = $this->walletGateway->resolve($handle, ['chain' => (string) ($request['chain'] ?? 'ARC-TESTNET')]);

            return match ($res->state) {
                'completed' => is_string($res->txHash) && preg_match('/^0x[0-9a-fA-F]{64}$/D', $res->txHash) === 1
                    ? TransportResolution::completed($res->txHash, $res->raw)
                    : TransportResolution::pending($res->raw),
                'failed' => TransportResolution::failed($res->reason ?? 'Circle reported transfer failed.', $res->raw),
                'pending' => TransportResolution::pending($res->raw),
                default => TransportResolution::unknown($res->reason ?? 'Transaction state unresolved on rail.', $res->raw),
            };
        }

        if ($this->http instanceof CircleAgentHttpClient) {
            $chain = (string) ($request['chain'] ?? 'ARC-TESTNET');

            try {
                $direct = $this->http->getTransaction($handle, $chain);
                if (is_array($direct) && strtolower((string) ($direct['id'] ?? '')) === strtolower($handle)) {
                    return $this->resolutionFromTransaction($direct);
                }
            } catch (Throwable) {
                // Fall back to transaction list lookup below
            }

            $transactions = $this->http->listTransactions($chain, isset($request['from']) ? (string) $request['from'] : null);
        } else {
            $listed = $this->run([
                'transaction', 'list',
                '--address', $request['from'],
                '--chain', $request['chain'],
                '--output', 'json',
            ]);

            $transactions = is_array($listed) && is_array($listed['data']['transactions'] ?? null)
                ? $listed['data']['transactions']
                : [];
        }

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }
            if (strtolower((string) ($transaction['id'] ?? '')) !== strtolower($handle)) {
                continue;
            }

            return $this->resolutionFromTransaction($transaction);
        }

        // The handle is simply absent from the history. That is not evidence the
        // transfer was never made, so it stays unresolved rather than failed.
        return TransportResolution::unknown('The rail did not return this transaction. Its fate is unresolved; reconcile before any retry.');
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function resolutionFromTransaction(array $transaction): TransportResolution
    {
        $state = strtoupper((string) ($transaction['state'] ?? ''));

        return match ($state) {
            'COMPLETE', 'CONFIRMED' => $this->hashOrPending($transaction),
            'FAILED', 'CANCELED', 'CANCELLED', 'REJECTED', 'DENIED' => TransportResolution::failed(
                'Circle reported the transfer as '.$state.'. No USDC moved; the nonce may be spent, so a retry needs a fresh submission.',
                ['state' => $state],
            ),
            default => TransportResolution::pending(['state' => $state]),
        };
    }

    /**
     * A completed transaction still has to yield a real on-chain hash.
     *
     * @param  array<string, mixed>  $transaction
     */
    private function hashOrPending(array $transaction): TransportResolution
    {
        $hash = $transaction['txHash'] ?? null;

        if (! is_string($hash) || preg_match('/^0x[0-9a-fA-F]{64}$/D', $hash) !== 1) {
            return TransportResolution::pending([
                'state' => 'COMPLETE',
                'reason' => 'Circle reports the transfer complete but has not published an on-chain hash yet.',
            ]);
        }

        return TransportResolution::completed($hash, ['state' => 'COMPLETE', 'blockHeight' => $transaction['blockHeight'] ?? null]);
    }

    /**
     * Run the CLI with argv, never a shell string (fallback when HTTP client is not injected).
     *
     * @param  array<int, string>  $args
     * @return array<string, mixed>|string
     */
    private function run(array $args): array|string
    {
        $process = new Process(array_merge([$this->binary], $args));
        $process->setTimeout($this->timeout);

        try {
            $process->run();
        } catch (Throwable $e) {
            throw new RuntimeException('Circle CLI could not be invoked: '.$e->getMessage(), $e->getCode(), previous: $e);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Circle CLI failed: '.implode(' ', $args).' — '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            return '';
        }

        $decoded = json_decode($output, true);

        return is_array($decoded) ? $decoded : $output;
    }
}
