<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Widgets;

use App\Models\Invoice;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InvoiceStatsWidget extends BaseWidget
{
    #[\Override]
    protected function getStats(): array
    {
        $totalCount = Invoice::query()->count();
        $totalAmount = (float) Invoice::query()->sum('amount');

        $settledQuery = Invoice::query()->whereIn('status', ['auto_paid', 'paid']);
        $settledCount = $settledQuery->count();
        $settledAmount = (float) $settledQuery->sum('amount');

        $pendingQuery = Invoice::query()->whereIn('status', ['pending', 'escalated', 'held']);
        $pendingCount = $pendingQuery->count();
        $pendingAmount = (float) $pendingQuery->sum('amount');

        $overdueQuery = Invoice::query()
            ->whereNotIn('status', ['auto_paid', 'paid', 'rejected'])
            ->where('due_date', '<', today());
        $overdueCount = $overdueQuery->count();
        $overdueAmount = (float) $overdueQuery->sum('amount');

        $settlementRate = $totalAmount > 0
            ? round(($settledAmount / $totalAmount) * 100, 1)
            : 0;

        return [
            Stat::make('Total Payables', number_format($totalAmount, 2).' USDC')
                ->description(number_format($totalCount).' institutional vendor invoices')
                ->descriptionIcon(Heroicon::DocumentText)
                ->color('primary'),

            Stat::make('Settled on Arc', number_format($settledAmount, 2).' USDC')
                ->description("{$settlementRate}% verified cryptographic settlement")
                ->descriptionIcon(Heroicon::CheckBadge)
                ->color('success'),

            Stat::make('Pending Review', number_format($pendingAmount, 2).' USDC')
                ->description(number_format($pendingCount).' invoices awaiting approval')
                ->descriptionIcon(Heroicon::Clock)
                ->color($pendingCount > 0 ? 'warning' : 'gray'),

            Stat::make('Overdue Invoices', number_format($overdueAmount, 2).' USDC')
                ->description(number_format($overdueCount).' past due date')
                ->descriptionIcon(Heroicon::ExclamationTriangle)
                ->color($overdueCount > 0 ? 'danger' : 'success'),
        ];
    }
}
