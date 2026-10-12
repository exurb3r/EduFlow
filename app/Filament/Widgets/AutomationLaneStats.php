<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Advisory\AutomationLaneStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Is the automation doing anything, and is any of it stuck?
 *
 * The numbers answer the two questions an operator asks first. "Owed" is work the
 * worker should pick up. "In flight" is work the rail already owns and only
 * reconciliation may touch — the one state where resending would pay twice.
 * "Unresolved" is the one that needs a human, because nobody knows what
 * happened.
 *
 * There is deliberately no "total paid" figure. That is a settlement question,
 * and only `ArcSettlementVerifier` may answer it. A count here would be a number
 * that looks like money and is not.
 */
class AutomationLaneStats extends StatsOverviewWidget
{
    #[\Override]
    protected function getStats(): array
    {
        $counts = app(AutomationLaneStatus::class)->counts();

        return [
            Stat::make('owed to the worker', $counts['owed'])
                ->description('queued or being submitted')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('warning'),

            Stat::make('in flight — the rail has it', $counts['in_flight'])
                ->description('accepted: do not resend, observation only')
                ->descriptionIcon('heroicon-m-lock-closed')
                ->color('info'),

            Stat::make('needs a human', $counts['unresolved'])
                ->description('nobody knows what happened, or the rail refused')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger'),

            Stat::make('verified settled', $counts['verified'])
                ->description('proven on chain, not merely submitted')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('success'),
        ];
    }
}
