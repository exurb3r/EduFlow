<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Widgets;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\InvoiceSettlement;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class InvoiceOverviewWidget extends Widget
{
    protected string $view = 'filament.resources.invoices.widgets.invoice-overview-widget';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    public function mount(?Model $record = null): void
    {
        $this->record = $record;
    }

    public function getInvoice(): ?Invoice
    {
        if ($this->record instanceof Invoice) {
            return $this->record;
        }

        return null;
    }

    #[\Override]
    protected function getViewData(): array
    {
        $invoice = $this->getInvoice();

        if (! $invoice) {
            return [];
        }

        $invoice->loadMissing(['vendor', 'budget', 'organization']);

        $verdict = InvoiceSettlement::verdictFor($invoice);
        $explanation = InvoiceSettlement::explanationFor($invoice);

        $transaction = Transaction::query()
            ->where('reference_type', Invoice::class)
            ->where('reference_id', $invoice->id)
            ->latest('id')
            ->first();

        $txHash = $transaction?->provider_tx_hash;
        $explorerUrl = null;
        if ($txHash) {
            try {
                $explorerUrl = app(ArcNetworkGateway::class)->explorerUrl($txHash);
            } catch (Throwable) {
                $explorerUrl = null;
            }
        } elseif ($invoice->vendor?->wallet_address) {
            try {
                $explorerUrl = app(ArcNetworkGateway::class)->addressExplorerUrl($invoice->vendor->wallet_address);
            } catch (Throwable) {
                $explorerUrl = null;
            }
        }

        $isSettled = in_array($invoice->status, ['auto_paid', 'paid'], true);
        $isOverdue = ! $isSettled && $invoice->due_date && $invoice->due_date->isPast();
        $daysUntilDue = $invoice->due_date ? (int) today()->diffInDays($invoice->due_date, false) : 0;

        return [
            'invoice' => $invoice,
            'vendor' => $invoice->vendor,
            'budget' => $invoice->budget,
            'amountFormatted' => number_format((float) $invoice->amount, 2).' USDC',
            'status' => $invoice->status,
            'verdict' => $verdict,
            'explanation' => $explanation,
            'transaction' => $transaction,
            'txHash' => $txHash,
            'explorerUrl' => $explorerUrl,
            'isSettled' => $isSettled,
            'isOverdue' => $isOverdue,
            'daysUntilDue' => $daysUntilDue,
        ];
    }
}
