<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Widgets\InvoiceStatsWidget;
use App\Models\Invoice;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Record new invoice')
                ->icon(Heroicon::PlusCircle),
        ];
    }

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            InvoiceStatsWidget::class,
        ];
    }

    /**
     * @return array<string, Tab>
     */
    #[\Override]
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Invoices')
                ->icon(Heroicon::DocumentText)
                ->badge(fn (): int => Invoice::query()->count()),

            'pending' => Tab::make('Pending Review')
                ->icon(Heroicon::Clock)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', ['pending', 'escalated', 'held']))
                ->badge(fn (): int => Invoice::query()->whereIn('status', ['pending', 'escalated', 'held'])->count())
                ->badgeColor('warning'),

            'settled' => Tab::make('Settled & Paid')
                ->icon(Heroicon::CheckBadge)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', ['auto_paid', 'paid']))
                ->badge(fn (): int => Invoice::query()->whereIn('status', ['auto_paid', 'paid'])->count())
                ->badgeColor('success'),

            'overdue' => Tab::make('Overdue Payables')
                ->icon(Heroicon::ExclamationTriangle)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotIn('status', ['auto_paid', 'paid', 'rejected'])->where('due_date', '<', today()))
                ->badge(fn (): int => Invoice::query()->whereNotIn('status', ['auto_paid', 'paid', 'rejected'])->where('due_date', '<', today())->count())
                ->badgeColor('danger'),

            'unbudgeted' => Tab::make('Unallocated')
                ->icon(Heroicon::Scale)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('budget_id'))
                ->badge(fn (): int => Invoice::query()->whereNull('budget_id')->count())
                ->badgeColor('gray'),
        ];
    }
}
