<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;

/**
 * Whether an invoice's recorded payment is actually backed by a chain receipt.
 *
 * Invoices are business documents, so their existence in the database is not
 * evidence of anything. What matters is whether the transaction that settled
 * them exists on Arc. Results are cached briefly because a table render would
 * otherwise make one RPC call per row.
 */
final readonly class InvoiceSettlement
{
    private const CACHE_KEY = 'lepton:invoice-settlement:';

    public function __construct(
        private ArcNetworkGateway $arc,
        private WalletGateway $wallets,
    ) {}

    public static function verdictFor(Invoice $invoice): string
    {
        return app(self::class)->verdict($invoice);
    }

    public static function explanationFor(Invoice $invoice): string
    {
        return app(self::class)->explain($invoice);
    }

    public function verdict(Invoice $invoice): string
    {
        if (! in_array($invoice->status, ['auto_paid', 'paid'], true)) {
            return 'not_settled';
        }

        return Cache::remember(
            self::CACHE_KEY.$invoice->id.':'.$invoice->updated_at?->timestamp,
            120,
            function () use ($invoice): string {
                $evidence = $this->paymentsFor($invoice);

                if ($evidence->isEmpty()) {
                    return 'unsettled';
                }

                foreach ($evidence as $transaction) {
                    if ($this->hashExists($transaction->provider_tx_hash)) {
                        return 'verified';
                    }
                }

                return 'unsupported';
            }
        );
    }

    public function explain(Invoice $invoice): string
    {
        $evidence = $this->paymentsFor($invoice);

        if ($evidence->isEmpty()) {
            return sprintf(
                'Marked %s but no settlement transaction is linked to this invoice.',
                $invoice->status
            );
        }

        $verdict = $this->verdict($invoice);

        return match ($verdict) {
            'verified' => 'Backed by a transaction found on '.strtoupper($this->arc->chainCode()).'.',
            'unsupported' => 'Marked '.$invoice->status.', but the linked receipt(s) are absent from '.strtoupper($this->arc->chainCode()).'. The payment cannot be proven.',
            default => 'Linked receipt could not be checked against the chain right now.',
        };
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function paymentsFor(Invoice $invoice)
    {
        return Transaction::query()
            ->where('reference_type', Invoice::class)
            ->where('reference_id', $invoice->id)
            ->get();
    }

    private function hashExists(?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            return false;
        }

        try {
            $tx = $this->arc->rpc('eth_getTransactionByHash', [$hash]);
        } catch (Throwable) {
            // Chain unreachable: fall back to the agent wallet's own history.
            return $this->knownToCircle($hash);
        }

        return is_array($tx) && $tx !== [];
    }

    private function knownToCircle(string $hash): bool
    {
        try {
            foreach ($this->wallets->transactions($this->wallets->treasuryAddress() ?? '', ['limit' => 200]) as $record) {
                if ($record->txHash !== null && strcasecmp($record->txHash, $hash) === 0) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
}
