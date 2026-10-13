<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * Circle agent wallets, over the Circle CLI.
 *
 * Two behaviours of the CLI shape this class, and both were confirmed against
 * the live tool rather than assumed:
 *
 *  - `circle wallet transfer` **without** `--quiet` does not broadcast. It
 *    prints a fee preview — `gasLimit`, `networkFee`, `baseFee`, `priorityFee`,
 *    `maxFee` — identical in shape to an explicit `--estimate`. Verified on
 *    ARC-TESTNET: a transfer run that way left no new entry in
 *    `circle transaction list` and moved no money.
 *  - with `--quiet`, an **agent** wallet yields a transaction *id* rather than a
 *    hash ("transaction hash for local wallets, transaction ID for agent
 *    wallets"). The hash only exists after the transaction completes.
 *
 * So `submit()` must pass `--quiet` and must never invent a hash, and
 * `resolve()` is the only place a hash may enter the system.
 */
final readonly class CircleAgentWalletTransport implements FeeBoundedPaymentTransport
{
    public function __construct(
        private string $binary,
        private int $timeout = 120,
        private int $usdcDecimals = 6,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            binary: (string) config('lepton.circle.bin', 'circle'),
            timeout: (int) config('lepton.circle.timeout', 120),
            usdcDecimals: (int) config('lepton.usdc_decimals', 6),
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
        $handle = $this->run([
            'wallet', 'transfer', $request['to'],
            '--amount', Amounts::toCliAmount((int) $request['amount_base_units'], $this->usdcDecimals),
            '--address', $request['from'],
            '--chain', $request['chain'],
            '--idempotency-key', $request['idempotency_key'],
            '--quiet',
        ]);

        // A handle is an opaque provider identifier. Accept only something that
        // is shaped like one, because whatever else the CLI printed is not
        // evidence that a transfer exists.
        $candidate = trim((string) ($handle['id'] ?? $handle['transactionId'] ?? $handle['transaction_id'] ?? ''));

        if ($candidate === '' && is_string($handle)) {
            $candidate = trim($handle);
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $candidate) !== 1) {
            throw new RuntimeException(
                'Circle CLI returned no transaction identifier for an agent-wallet transfer; the submission state is unknown, not settled.'
            );
        }

        return new TransportAcceptance(handle: strtolower($candidate), raw: ['raw' => $handle]);
    }

    /**
     * The installed CLI sets no fee ceiling.
     *
     * `circle wallet transfer` exposes `--amount`, `--token`, `--address`,
     * `--chain`, `--rpc-url`, `--idempotency-key`, `--estimate`, `--output` and
     * `--quiet` — there is no fee or gas-limit flag, so the transaction's
     * `maxFeePerGas` is chosen entirely by the CLI. But that flag is irrelevant
     * here, because the CLI does not transact from the payer's account at all:
     * a Circle agent wallet transfer is relayed, and Circle sponsors the gas.
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
     * Fee preflight through the rail itself.
     *
     * `--estimate` never broadcasts, which is exactly what a preflight must be.
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
        $quoted = $this->run([
            'wallet', 'transfer', $request['to'],
            '--amount', Amounts::toCliAmount((int) $request['amount_base_units'], $this->usdcDecimals),
            '--address', $request['from'],
            '--chain', $request['chain'],
            '--estimate',
            '--output', 'json',
        ]);

        return is_array($quoted) ? $quoted : null;
    }

    public function resolve(string $handle, array $request): TransportResolution
    {
        $listed = $this->run([
            'transaction', 'list',
            '--address', $request['from'],
            '--chain', $request['chain'],
            '--output', 'json',
        ]);

        $transactions = is_array($listed['data']['transactions'] ?? null)
            ? $listed['data']['transactions']
            : [];

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }
            if (strtolower((string) ($transaction['id'] ?? '')) !== strtolower($handle)) {
                continue;
            }
            $state = strtoupper((string) ($transaction['state'] ?? ''));

            return match ($state) {
                'COMPLETE' => $this->hashOrPending($transaction),
                'FAILED', 'CANCELED', 'REJECTED' => TransportResolution::failed(
                    'Circle reported the transfer as '.$state.'. No USDC moved; the nonce may be spent, so a retry needs a fresh submission.',
                    ['state' => $state],
                ),
                default => TransportResolution::pending(['state' => $state]),
            };
        }

        // The handle is simply absent from the history. That is not evidence the
        // transfer was never made, so it stays unresolved rather than failed.
        return TransportResolution::unknown('The rail did not return this transaction. Its fate is unresolved; reconcile before any retry.');
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
     * Run the CLI with argv, never a shell string.
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
