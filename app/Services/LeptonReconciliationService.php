<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

/**
 * Proves whether a recorded transaction actually settled on Arc.
 *
 * The database is EduFlow's own record and cannot vouch for itself. Every
 * claim of on-chain settlement is re-checked against the chain (or against
 * the Circle agent wallet's own history when RPC access is unavailable), so
 * fabricated hashes cannot masquerade as receipts.
 */
class LeptonReconciliationService
{
    public function __construct(
        private readonly ArcNetworkGateway $arc,
        private readonly WalletGateway $wallets,
    ) {}

    /**
     * @return array{
     *     verdict:string, checked:string, reason:string, block:?int, on_chain:?array<string,mixed>, chain_source:string
     * }
     */
    public function verify(Transaction $transaction): array
    {
        $hash = $transaction->provider_tx_hash;

        // A ledger credit never claims a receipt, so there is nothing to prove.
        if ($hash === null || $hash === '') {
            return $this->result(
                $transaction,
                'ledger_only',
                'No tx hash: recorded as a local ledger entry, not a chain transfer.',
            );
        }

        $rpc = $this->viaChain($hash);

        if ($rpc['found']) {
            return $this->result(
                $transaction,
                'verified',
                'Found on '.strtoupper($this->arc->chainCode()).' in block '.($rpc['block'] ?? '?').'.',
                $rpc['block'],
                $rpc['tx'],
                'rpc',
            );
        }

        // RPC reachable but nothing there: the hash was never settled.
        if ($rpc['reachable']) {
            return $this->result(
                $transaction,
                'fabricated',
                'Hash is well-formed but absent from '.strtoupper($this->arc->chainCode()).'. This was never settled.',
                null,
                null,
                'rpc',
            );
        }

        // RPC unavailable: fall back to the Circle agent wallet's own history.
        $wallet = $transaction->wallet;
        $circle = $this->viaCircle($wallet?->address, $hash);

        if ($circle === null) {
            return $this->result(
                $transaction,
                'unverifiable',
                'Chain reads unavailable and no agent session to cross-check, so this receipt cannot be confirmed.',
                null,
                null,
                'none',
            );
        }

        return $circle
            ? $this->result($transaction, 'verified', 'Confirmed in the Circle agent wallet history.', null, null, 'circle')
            : $this->result($transaction, 'fabricated', 'Absent from the Circle agent wallet history, so it was never settled.', null, null, 'circle');
    }

    /**
     * @return array{verdict:string, total:int, verified:int, fabricated:int, unverifiable:int, ledger_only:int, items:Collection<int, array<string,mixed>>}
     */
    public function verifyAll(?int $organizationId = null, int $limit = 200): array
    {
        $query = Transaction::query()->with('wallet')->latest('id');

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $items = $query->limit($limit)->get()
            ->map(fn (Transaction $t): array => ['transaction' => $t, ...$this->verify($t)])
            ->values();

        return [
            'verdict' => $items->contains(fn (array $i): bool => $i['verdict'] === 'fabricated')
                ? 'discrepancies'
                : 'clean',
            'total' => $items->count(),
            'verified' => $items->where('verdict', 'verified')->count(),
            'fabricated' => $items->where('verdict', 'fabricated')->count(),
            'unverifiable' => $items->where('verdict', 'unverifiable')->count(),
            'ledger_only' => $items->where('verdict', 'ledger_only')->count(),
            'items' => $items,
        ];
    }

    /**
     * Invoices are business documents, not chain data. What can be proven is
     * whether the payment that settled them actually happened.
     *
     * @return array{invoice: Invoice, settlement_verdict: string, evidence: Collection<int, Transaction>}
     */
    public function verifyInvoiceSettlement(Invoice $invoice): array
    {
        $evidence = Transaction::query()
            ->where('reference_type', Invoice::class)
            ->where('reference_id', $invoice->id)
            ->get();

        if ($evidence->isEmpty()) {
            return ['invoice' => $invoice, 'settlement_verdict' => 'unsettled', 'evidence' => $evidence];
        }

        $verified = $evidence->filter(fn (Transaction $t): bool => $this->verify($t)['verdict'] === 'verified');

        if ($verified->isNotEmpty()) {
            return ['invoice' => $invoice, 'settlement_verdict' => 'verified', 'evidence' => $evidence];
        }

        return ['invoice' => $invoice, 'settlement_verdict' => 'unsupported', 'evidence' => $evidence];
    }

    /**
     * @return array{found:bool, reachable:bool, block:?int, tx:?array<string,mixed>}
     */
    private function viaChain(string $hash): array
    {
        try {
            $tx = $this->arc->rpc('eth_getTransactionByHash', [$hash]);
        } catch (Throwable) {
            return ['found' => false, 'reachable' => false, 'block' => null, 'tx' => null];
        }

        if (! is_array($tx) || $tx === []) {
            return ['found' => false, 'reachable' => true, 'block' => null, 'tx' => null];
        }

        $block = $tx['blockNumber'] ?? null;

        return [
            'found' => true,
            'reachable' => true,
            'block' => is_string($block) ? (int) hexdec(ltrim($block, '0x') ?: '0') : null,
            'tx' => $tx,
        ];
    }

    private function viaCircle(?string $address, string $hash): ?bool
    {
        if ($address === null) {
            return null;
        }

        try {
            $records = $this->wallets->transactions($address, ['limit' => 200]);
        } catch (Throwable) {
            return null;
        }

        foreach ($records as $record) {
            if ($record->txHash !== null && strcasecmp($record->txHash, $hash) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $onChain
     * @return array{verdict:string, checked:string, reason:string, block:?int, on_chain:?array<string,mixed>, chain_source:string}
     */
    private function result(Transaction $transaction, string $verdict, string $reason, ?int $block = null, ?array $onChain = null, string $source = 'rpc'): array
    {
        return [
            'verdict' => $verdict,
            'checked' => $transaction->provider_tx_hash ?? '(none)',
            'reason' => $reason,
            'block' => $block,
            'on_chain' => $onChain,
            'chain_source' => $source,
        ];
    }
}
