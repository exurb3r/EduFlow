<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSubmissions\Pages;

use App\Filament\Pages\PaymentReviews;
use App\Filament\Resources\PaymentSubmissions\PaymentSubmissionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPaymentSubmissions extends ListRecords
{
    protected static string $resource = PaymentSubmissionResource::class;

    protected static ?string $title = 'Automated payment submission';

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('paymentApprovals')
                ->label('Payment approvals')
                ->icon('heroicon-o-clipboard-document-check')
                ->visible(fn (): bool => PaymentReviews::canAccess())
                ->url(fn (): string => PaymentReviews::getUrl()),
        ];
    }

    /**
     * The description exists because the first thing anyone asks of this table
     * is what it is. It says plainly that nothing here can be edited and why.
     */
    #[\Override]
    public function getSubheading(): ?string
    {
        return 'Every payment the automation has attempted, and exactly how far each one got. '
            .'This table is read-only: a submission is written by the authorization workflow inside '
            .'its own transaction, and only the worker or reconciliation may advance one.';
    }
}
