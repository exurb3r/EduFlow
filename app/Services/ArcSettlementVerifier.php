<?php

declare(strict_types=1);

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * The strict predicate that decides whether a submitted payment actually settled.
 *
 * Finding a transaction is not proof. This service requires **execution success**
 * in a committed block **and** matching economic movement — chain, sender,
 * recipient and amount — before it will say `verified`. A hash that is merely
 * present proves nothing, and a hash that is absent proves nothing either.
 *
 * Every non-`verified` outcome is deliberately non-committal about fault:
 * `pending`, `dropped`, `not_found` and `unreadable` all mean "unresolved,
 * investigate", never "fabricated". Declaring fabrication from a null lookup is
 * the specific defect this replaces — a provider or RPC that cannot answer is
 * not evidence of wrongdoing.
 *
 * Arc surfaces USDC through two event streams that share one balance:
 *
 *  - the EIP-7708 native system emitter at 18 decimals, which logs every
 *    explicit USDC movement, and
 *  - the NativeFiatToken ERC-20 contract at 6 decimals, which logs only
 *    activity on the ERC-20 interface.
 *
 * A single ERC-20 `transfer()` therefore emits **two** logs, and a plain native
 * send emits the system log alongside a non-zero `value`. The decimals are
 * chosen by emitter address, never assumed from the log's shape, because mixing
 * them is off by a factor of a million. Corroboration across streams is
 * agreement about one movement, not a double-count, so it is reported as
 * agreement; only a genuine absence of the authorized movement is
 * `mismatched`.
 */
final readonly class ArcSettlementVerifier
{
    /** Canonical ERC-20 Transfer event topic. */
    private const string TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    /**
     * Emitter address => decimals of that emitter's Transfer `data`.
     *
     * Anything absent from this map has an unknown scale and is skipped rather
     * than guessed at: a misread log is how a real payment gets called
     * mismatched.
     */
    private const array USDC_EMITTERS = [
        '0xfffffffffffffffffffffffffffffffffffffffe' => 18,
        '0x3600000000000000000000000000000000000000' => 6,
    ];

    public function __construct(private ArcNetworkGateway $arc) {}

    /**
     * @param  array{chain:string, chain_id:int, sender:string, recipient:string, amount_base_units:int, max_fee_base_units:int}  $expected
     * @return array<string, mixed>
     */
    public function verify(array $expected, string $txHash): array
    {
        if (preg_match('/^0x[0-9a-fA-F]{64}$/D', $txHash) !== 1) {
            return $this->verdict('not_found', 'Provider reference is not a transaction hash.', null);
        }

        $receipt = $this->call('eth_getTransactionReceipt', [$txHash]);

        if ($receipt === 'unreadable') {
            return $this->verdict('unreadable', 'Chain reads failed. The outcome is unknown and requires investigation.', null);
        }

        if ($receipt === null) {
            return $this->classifyWithoutReceipt($txHash);
        }

        return $this->classifyReceipt($receipt, $expected, $txHash);
    }

    /**
     * @return array<string, mixed>
     */
    private function classifyWithoutReceipt(string $txHash): array
    {
        $transaction = $this->call('eth_getTransactionByHash', [$txHash]);

        if ($transaction === 'unreadable') {
            return $this->verdict('unreadable', 'Chain reads failed while locating the submission. Outcome unknown.', null);
        }

        if ($transaction === null) {
            // Not being found is not proof of fabrication. A dropped or
            // never-propagated transaction looks exactly like this.
            return $this->verdict('not_found', 'Hash is well-formed but absent from the chain. Absence is not evidence of fabrication; reconcile against the provider before concluding anything.', null);
        }

        if (! is_string($transaction['blockNumber'] ?? null)) {
            return $this->verdict('pending', 'Transaction is known but not yet included in a block. It may still settle.', null);
        }

        return $this->verdict('dropped', 'Transaction claims a block but has no receipt. Treated as unresolved, not settled and not fraudulent.', null);
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>
     */
    private function classifyReceipt(array $receipt, array $expected, string $txHash): array
    {
        $blockNumber = $receipt['blockNumber'] ?? null;

        if (! is_string($blockNumber) || preg_match('/^0x[0-9a-fA-F]+$/D', $blockNumber) !== 1) {
            return $this->verdict('pending', 'Receipt carries no committed block number yet.', $receipt);
        }

        // Arc has deterministic finality: a receipt is immediately
        // authoritative, there are no reorgs, and confirmation count 1 is
        // documented as correct. This lookup is therefore corroboration, not
        // the basis of the verdict -- it cannot make a settled payment look
        // unsettled. It is kept because it yields the block hash and timestamp
        // that make the recorded evidence independently checkable, and because
        // losing that evidence is worse than one extra read.
        $block = $this->call('eth_getBlockByNumber', [$blockNumber, false]);
        $block = is_array($block) ? $block : null;

        $status = is_string($receipt['status'] ?? null) ? strtolower($receipt['status']) : null;
        if ($status !== '0x1') {
            // Arc includes a reverted transaction irreversibly: gas is consumed
            // and the nonce is spent, so a retry needs a new nonce. This is a
            // final failure, never a pending state to wait on.
            return $this->verdict('reverted', 'Execution did not succeed. The transaction is final but its state changes were rolled back, and the nonce is spent.', $receipt);
        }

        $transaction = $this->call('eth_getTransactionByHash', [$txHash]);
        if ($transaction === 'unreadable' || ! is_array($transaction)) {
            return $this->verdict('unreadable', 'Receipt exists but its transaction could not be read for economic matching.', $receipt);
        }

        $economic = $this->economicMatch($transaction, $receipt, $expected);
        if ($economic['mismatch'] !== null) {
            return $this->verdict('mismatched', $economic['mismatch'], $receipt);
        }

        $feeBaseUnits = $this->actualFeeBaseUnits($receipt);
        if ($feeBaseUnits !== null && $feeBaseUnits > $expected['max_fee_base_units']) {
            return $this->verdict('fee_exceeded', sprintf(
                'Actual fee %s USDC exceeded the approved ceiling of %s USDC.',
                Amounts::toDecimalString($feeBaseUnits, 6),
                Amounts::toDecimalString($expected['max_fee_base_units'], 6),
            ), $receipt);
        }

        $explorer = $this->explorerUrl($txHash);

        return $this->verdict('verified', sprintf(
            'Execution succeeded in block %s with matching sender, recipient and amount, confirmed by %s. Fee %s USDC.',
            Amounts::fromHexQuantity($blockNumber, 0),
            implode(' and ', $economic['interfaces']),
            $feeBaseUnits === null ? 'unreadable' : Amounts::toDecimalString($feeBaseUnits, 6),
        ), $receipt, [
            'block_hash' => $block['hash'] ?? null,
            'block_timestamp' => $block['timestamp'] ?? null,
            'actual_fee_base_units' => $feeBaseUnits,
            // Recorded so a later reviewer can see *which* evidence settled it,
            // and re-derive the same verdict from the same receipt.
            'matched_interfaces' => $economic['interfaces'],
            'explorer_url' => $explorer,
        ]);
    }

    /**
     * Compare the movement actually recorded against what was authorized.
     *
     * The sender is established from the USDC Transfer log, not from the
     * transaction envelope. Circle agent wallets execute through a relayer and
     * a delegated account, so on that rail `tx.from` is the relayer, `tx.to` is
     * the delegate and `value` is zero. Confirmed against real ARC-TESTNET
     * receipts: the treasury is nonetheless the `from` topic of the system
     * Transfer log. Reading the sender off the envelope would refuse every
     * genuine agent-wallet payment while proving nothing about the money.
     *
     * The log is the stronger evidence anyway -- it ties the sender to this
     * specific movement rather than to the transaction that carried it, and it
     * cannot be forged, because the chain emits it.
     *
     * Several streams can describe one movement: a plain native send carries
     * both a non-zero `value` and a system-emitter log, and an ERC-20 transfer
     * emits two logs. Every agreeing stream is collected, because on Arc they
     * are independent descriptions of the same money rather than a
     * double-count. Only a complete absence of the authorized movement is a
     * mismatch.
     *
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $expected
     * @return array{mismatch: string|null, interfaces: list<string>}
     */
    private function economicMatch(array $transaction, array $receipt, array $expected): array
    {
        $interfaces = $this->transferLogMatches($receipt, $expected);

        if ($this->nativeValueMatches($transaction, $expected)) {
            $interfaces[] = 'native_value';
        }

        if ($interfaces === []) {
            return ['mismatch' => 'No USDC Transfer log and no native value records the authorized sender, recipient and amount.', 'interfaces' => []];
        }

        return ['mismatch' => null, 'interfaces' => $interfaces];
    }

    /**
     * Interpret 6-decimal USDC base units as their exact decimal value.
     *
     * Comparing an 18-decimal native reading against a raw base-unit integer
     * is an easy way to be off by a factor of a million, so both interfaces
     * convert through here instead.
     */
    private function authorizedAmount(int $baseUnits): BigDecimal
    {
        return BigDecimal::of($baseUnits)->withPointMovedLeft(6);
    }

    /**
     * Read a 0x-prefixed hex quantity as an exact decimal.
     *
     * Returns null for anything that is not a hex quantity. This is the one
     * place untrusted provider data is parsed defensively: a malformed reading
     * is "cannot confirm", never an error worth aborting a reconciliation run
     * over, and never something to be guessed at.
     */
    private function hexQuantity(string $quantity, int $decimals): ?BigDecimal
    {
        if (preg_match('/^0x[0-9a-fA-F]+$/D', $quantity) !== 1) {
            return null;
        }

        try {
            return BigDecimal::of(Amounts::fromHexQuantity($quantity, $decimals));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Native Arc USDC is 18 decimals; the authorized amount is 6. Compare as
     * exact decimals rather than strings so `25.000001` can never be rounded
     * down onto `25.000000`.
     *
     * A plain native send carries the movement in `value`, but only an ordinary
     * account-to-account send does. A relayed agent-wallet transfer has
     * `value` zero and moves the USDC through a log instead, so this correctly
     * declines it and the log stream carries the proof.
     *
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $expected
     */
    private function nativeValueMatches(array $transaction, array $expected): bool
    {
        $value = $transaction['value'] ?? null;
        $from = $transaction['from'] ?? null;
        $to = $transaction['to'] ?? null;

        if (! is_string($value) || ! is_string($from) || ! is_string($to)
            || strtolower($from) !== strtolower($expected['sender'])
            || strtolower($to) !== strtolower($expected['recipient'])) {
            return false;
        }

        $moved = $this->hexQuantity($value, 18);

        return $moved instanceof BigDecimal && $moved->isEqualTo($this->authorizedAmount($expected['amount_base_units']));
    }

    /**
     * Find every USDC Transfer stream that reports the authorized movement.
     *
     * @param  array<string, mixed>  $receipt
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    private function transferLogMatches(array $receipt, array $expected): array
    {
        $logs = $receipt['logs'] ?? null;

        if (! is_array($logs)) {
            return [];
        }

        $matched = [];

        foreach ($logs as $log) {
            // `topics` is a list; `data` is a single 32-byte hex payload, not
            // a list of its own.
            if (! is_array($log)) {
                continue;
            }
            if (! is_array($log['topics'] ?? null)) {
                continue;
            }
            if (! is_string($log['data'] ?? null)) {
                continue;
            }
            if (strtolower((string) ($log['topics'][0] ?? '')) !== self::TRANSFER_TOPIC) {
                continue;
            }
            if (count($log['topics']) < 3) {
                continue;
            }
            // Decimals are a property of the emitter, never inferred from the
            // payload. Reading an 18-decimal system log as 6-decimal is how a
            // genuinely settled payment gets recorded as `mismatched`, and an
            // emitter with an unknown scale is skipped rather than guessed at.
            $decimals = self::USDC_EMITTERS[strtolower((string) ($log['address'] ?? ''))] ?? null;

            if ($decimals === null) {
                continue;
            }

            $logFrom = '0x'.substr(strtolower((string) $log['topics'][1]), 26);
            $logTo = '0x'.substr(strtolower((string) $log['topics'][2]), 26);
            if ($logFrom !== strtolower($expected['sender'])) {
                continue;
            }
            if ($logTo !== strtolower($expected['recipient'])) {
                continue;
            }

            $moved = $this->hexQuantity((string) $log['data'], $decimals);

            if ($moved instanceof BigDecimal && $moved->isEqualTo($this->authorizedAmount($expected['amount_base_units']))) {
                $matched[] = $decimals === 18 ? 'native_system_log' : 'erc20_log';
            }
        }

        return array_values(array_unique($matched));
    }

    /**
     * Gas fees are not transfer events and are paid in the native asset.
     *
     * @param  array<string, mixed>  $receipt
     */
    private function actualFeeBaseUnits(array $receipt): ?int
    {
        $gasUsed = $receipt['gasUsed'] ?? null;
        $price = $receipt['effectiveGasPrice'] ?? null;

        if (! is_string($gasUsed) || ! is_string($price)) {
            return null;
        }

        try {
            $gas = $this->hexQuantity($gasUsed, 0);
            $pricePerGas = $this->hexQuantity($price, 0);

            if (! $gas instanceof BigDecimal || ! $pricePerGas instanceof BigDecimal) {
                return null;
            }

            // 18-decimal native value expressed in 6-decimal USDC base units,
            // with any sub-unit remainder dropped rather than rounded up.
            // `toInt()` is required: casting the quotient object directly
            // silently yields a wrong number, which would make every fee
            // ceiling comparison pass.
            return $gas->multipliedBy($pricePerGas)
                ->dividedBy(BigInteger::of('1000000000000'), 0, RoundingMode::Floor)
                ->toInt();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|'unreadable'
     */
    private function call(string $method, array $params): array|null|string
    {
        try {
            $result = $this->arc->rpc($method, $params);
        } catch (Throwable) {
            return 'unreadable';
        }

        if (! is_array($result)) {
            return null;
        }

        return $result === [] ? null : $result;
    }

    private function explorerUrl(string $txHash): ?string
    {
        try {
            return $this->arc->explorerUrl($txHash);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function verdict(string $verdict, string $reason, ?array $evidence, array $extra = []): array
    {
        return [
            'verdict' => $verdict,
            // Only this one means money moved.
            'settled' => $verdict === 'verified',
            'reason' => $reason,
            'fabricated' => false,
            // Every outcome short of `verified` needs a human, including
            // `not_found`: absence is unresolved, never a conclusion.
            'requires_investigation' => $verdict !== 'verified',
            'receipt' => $evidence,
            ...$extra,
        ];
    }
}
