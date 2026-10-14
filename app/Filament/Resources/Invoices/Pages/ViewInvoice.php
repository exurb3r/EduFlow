<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Widgets\InvoiceOverviewWidget;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\InvoiceSettlement;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            InvoiceOverviewWidget::class,
        ];
    }

    #[\Override]
    public function getRecord(): Invoice
    {
        /** @var Invoice $record */
        $record = parent::getRecord();

        return $record;
    }

    #[\Override]
    public function getSubheading(): ?string
    {
        $invoice = $this->getRecord();
        $vendorName = $invoice->vendor?->name ?? 'Direct Vendor';
        $amount = number_format((float) $invoice->amount, 2);

        return "{$invoice->reference} • Vendor: {$vendorName} • {$amount} USDC";
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('reverify')
                ->label('Verify on Arc')
                ->icon(Heroicon::ShieldCheck)
                ->color('primary')
                ->action(function (): void {
                    $invoice = $this->getRecord();
                    $verdict = InvoiceSettlement::verdictFor($invoice);
                    $explanation = InvoiceSettlement::explanationFor($invoice);

                    Notification::make()
                        ->title("Settlement Status: {$verdict}")
                        ->body($explanation)
                        ->color(match ($verdict) {
                            'verified' => 'success',
                            'unsupported' => 'danger',
                            default => 'info',
                        })
                        ->send();
                }),

            Action::make('arcscan')
                ->label('ArcScan Receipt')
                ->icon(Heroicon::ArrowTopRightOnSquare)
                ->color('gray')
                ->visible(function (): bool {
                    $invoice = $this->getRecord();
                    $tx = Transaction::query()
                        ->where('reference_type', Invoice::class)
                        ->where('reference_id', $invoice->id)
                        ->latest('id')
                        ->first();

                    return filled($tx?->provider_tx_hash) || filled($invoice->vendor?->wallet_address);
                })
                ->url(function (): ?string {
                    $invoice = $this->getRecord();
                    $tx = Transaction::query()
                        ->where('reference_type', Invoice::class)
                        ->where('reference_id', $invoice->id)
                        ->latest('id')
                        ->first();

                    if ($tx?->provider_tx_hash) {
                        try {
                            return app(ArcNetworkGateway::class)->explorerUrl($tx->provider_tx_hash);
                        } catch (Throwable) {
                            return null;
                        }
                    }

                    if ($invoice->vendor?->wallet_address) {
                        try {
                            return app(ArcNetworkGateway::class)->addressExplorerUrl($invoice->vendor->wallet_address);
                        } catch (Throwable) {
                            return null;
                        }
                    }

                    return null;
                })
                ->openUrlInNewTab(),

            Action::make('emailVendor')
                ->label('Email vendor')
                ->icon(Heroicon::Envelope)
                ->color('gray')
                ->visible(fn (): bool => filled($this->getRecord()->vendor?->email))
                ->url(fn (): string => "mailto:{$this->getRecord()->vendor?->email}"),

            EditAction::make()
                ->icon(Heroicon::PencilSquare),
        ];
    }
}
