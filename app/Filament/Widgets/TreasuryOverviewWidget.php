<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\AgentDecision;
use App\Models\Invoice;
use App\Models\Organization;
use App\Services\TreasuryForecastService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TreasuryOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $org = Organization::first();
        if (! $org) {
            return [
                Stat::make('EduFlow AI', 'Initializing...')
                    ->description('Run database seeder to load Northstar Academy')
                    ->color('gray'),
            ];
        }

        $wallet = $org->primaryWallet();
        $balance = $wallet ? (float) $wallet->balance : 0.00;
        $reserve = (float) $org->minimum_reserve;

        $forecastService = app(TreasuryForecastService::class);
        $forecast = $wallet ? $forecastService->forecast($org, $wallet, 30) : null;

        $pendingInvoicesCount = Invoice::where('organization_id', $org->id)
            ->where('status', 'pending')
            ->count();
        $pendingAmount = (float) Invoice::where('organization_id', $org->id)
            ->where('status', 'pending')
            ->sum('amount');

        $autoPaidToday = AgentDecision::where('organization_id', $org->id)
            ->where('decision', 'auto_approve')
            ->count();
        $escalatedToday = AgentDecision::where('organization_id', $org->id)
            ->where('decision', 'escalate')
            ->count();

        $healthColor = match ($forecast?->healthStatus) {
            'SAFE' => 'success',
            'WARNING' => 'warning',
            default => 'danger',
        };

        $healthDesc = match ($forecast?->healthStatus) {
            'SAFE' => 'Reserve Safe (+'.number_format(max(0, $balance - $reserve), 2).' USDC buffer)',
            'WARNING' => 'Reserve Warning: Buffer under 15%',
            default => 'Critical: Reserve breach expected',
        };

        return [
            Stat::make('Treasury Balance', number_format($balance, 2).' USDC')
                ->description('Circle Wallet on Arc Network')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success')
                ->chart([22000, 23500, 24100, 25000, $balance]),

            Stat::make('Minimum Reserve', number_format($reserve, 2).' USDC')
                ->description($healthDesc)
                ->descriptionIcon('heroicon-m-shield-check')
                ->color($healthColor),

            Stat::make('Scheduled Obligations', number_format($pendingAmount, 2).' USDC')
                ->description("{$pendingInvoicesCount} invoices pending evaluation")
                ->descriptionIcon('heroicon-m-clock')
                ->color('info'),

            Stat::make('AI Autonomy', "{$autoPaidToday} Auto-Paid • {$escalatedToday} Escalated")
                ->description('Bounded Financial Operator Active')
                ->descriptionIcon('heroicon-m-sparkles')
                ->color('primary'),
        ];
    }
}
