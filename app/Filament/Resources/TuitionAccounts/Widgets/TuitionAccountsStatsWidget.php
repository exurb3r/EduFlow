<?php

declare(strict_types=1);

namespace App\Filament\Resources\TuitionAccounts\Widgets;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\TuitionAccount;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TuitionAccountsStatsWidget extends BaseWidget
{
    #[\Override]
    protected function getStats(): array
    {
        $totalAccounts = TuitionAccount::query()->count();
        $totalAssessedUnits = (int) TuitionAccount::query()->sum('total_amount');
        $totalPaidUnits = (int) TuitionAccount::query()->sum('paid_amount');
        $totalRemainingUnits = max(0, $totalAssessedUnits - $totalPaidUnits);

        $settlementRate = $totalAssessedUnits > 0
            ? round(($totalPaidUnits / $totalAssessedUnits) * 100, 1)
            : 0;

        $assessedMoney = (new Money($totalAssessedUnits, CurrencyCode::USDC))->format(2).' USDC';
        $paidMoney = (new Money($totalPaidUnits, CurrencyCode::USDC))->format(2).' USDC';
        $remainingMoney = (new Money($totalRemainingUnits, CurrencyCode::USDC))->format(2).' USDC';

        return [
            Stat::make('Tuition Accounts', number_format($totalAccounts))
                ->description('Active term-scoped accounts')
                ->descriptionIcon(Heroicon::DocumentText)
                ->color('primary'),

            Stat::make('Assessed Tuition', $assessedMoney)
                ->description('Total fees billed to students')
                ->descriptionIcon(Heroicon::Banknotes)
                ->color('info'),

            Stat::make('Settled Payments', $paidMoney)
                ->description("{$settlementRate}% collected on Arc Network")
                ->descriptionIcon(Heroicon::CheckBadge)
                ->color('success'),

            Stat::make('Outstanding Receivables', $remainingMoney)
                ->description('Pending student tuition debt')
                ->descriptionIcon(Heroicon::Scale)
                ->color($totalRemainingUnits > 0 ? 'warning' : 'success'),
        ];
    }
}
