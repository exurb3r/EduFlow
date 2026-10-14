<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\DTOs\Money;
use App\Filament\Pages\TreasuryWallet;
use App\Models\User;
use App\Services\FinanceAffordability;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class FinanceAffordabilityWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    #[\Override]
    protected function getHeading(): ?string
    {
        return 'Can we cover our bills?';
    }

    #[\Override]
    protected function getDescription(): ?string
    {
        return 'Observed wallet cash, protected funds and unpaid bills. Budget allocation is not cash; this view cannot approve payment.';
    }

    #[\Override]
    protected function getStats(): array
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            return [];
        }
        $summary = app(FinanceAffordability::class)->summarize($actor);
        $url = TreasuryWallet::getUrl(panel: 'finance');

        return [
            Stat::make('Observed Arc balance', Money::formatExact($summary['observation']['balance_base_units'], 'USDC'))
                ->description($summary['observation']['available'] ? 'Block '.$summary['observation']['block_number'].' · not payment authority' : $summary['observation']['error'])
                ->color($summary['observation']['available'] ? 'info' : 'warning')->url($url),
            Stat::make('After protections', Money::formatExact($summary['available_base_units'], 'USDC'))
                ->description('Less reserve, restricted receipts and committed payment capacity')->url($url),
            Stat::make('Unpaid bills', Money::formatExact($summary['unpaid_base_units'], 'USDC'))
                ->description($summary['unpaid_count'].' bills · '.$summary['overdue_count'].' overdue')->url($url),
            Stat::make('Funding gap', Money::formatExact($summary['funding_gap_base_units'], 'USDC'))
                ->description($summary['funding_gap_base_units'] === null ? 'Resolve policy and balance evidence first' : 'For uncommitted bills; approval and budgets still apply')
                ->color($summary['funding_gap_base_units'] === '0' ? 'info' : 'warning')->url($url),
        ];
    }
}
