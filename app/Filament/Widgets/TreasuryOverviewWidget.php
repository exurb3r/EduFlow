<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\TransactionType;
use App\Models\AgentDecision;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\InstallationInstitution;
use App\Services\LeptonTreasuryService;
use App\Services\TreasuryForecastService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TreasuryOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $org = app(InstallationInstitution::class)->current();
        if (! $org) {
            return [
                Stat::make('EduFlow AI', 'Initializing...')
                    ->description('Configure EDUFLOW_INSTITUTION_ID for a single institution')
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

        $chain = app(LeptonTreasuryService::class)->status($wallet);

        // Real settlement history, newest last, so the sparkline is not invented.
        $history = Transaction::where('wallet_id', $wallet?->id)
            ->where('type', '!=', TransactionType::TUITION_REVENUE->value)
            ->orderByDesc('created_at')
            ->limit(6)
            ->get(['amount'])
            ->reverse()
            ->map(fn (Transaction $t): float => $balance + (float) $t->amount)
            ->all();

        $balanceStat = Stat::make('Treasury Balance', number_format($balance, 2).' USDC')
            ->description($chain['live_available']
                ? 'EduFlow ledger on '.strtoupper($chain['chain'])
                : 'EduFlow ledger · live chain reads unavailable')
            ->descriptionIcon('heroicon-m-banknotes')
            ->color($chain['in_sync'] === false ? 'warning' : 'success');

        if ($chain['live_available']) {
            $balanceStat->description(sprintf(
                'Live on-chain %s USDC · %s',
                number_format((float) $chain['onchain_balance'], 2),
                $chain['in_sync'] ? 'ledger in sync' : 'drift '.$chain['drift'].' USDC',
            ));
        }

        if ($history !== []) {
            $balanceStat->chart($history);
        }

        return [
            $balanceStat,

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
