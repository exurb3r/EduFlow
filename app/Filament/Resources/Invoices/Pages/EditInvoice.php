<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

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
        $vendorName = $invoice->vendor?->name ?? 'Vendor';

        return "{$invoice->reference} • {$vendorName} • ".number_format((float) $invoice->amount, 2).' USDC';
    }

    #[\Override]
    protected function getSavedNotificationTitle(): ?string
    {
        return 'Invoice updated successfully';
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->icon(Heroicon::Eye),
            DeleteAction::make(),
        ];
    }
}
