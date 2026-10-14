<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    #[\Override]
    public function getSubheading(): ?string
    {
        return 'Record an institutional vendor invoice for departmental review and Arc Network settlement.';
    }

    #[\Override]
    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Invoice recorded successfully';
    }
}
