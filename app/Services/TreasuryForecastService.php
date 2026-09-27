<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\TreasuryForecast;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Wallet;
use Illuminate\Support\Carbon;

class TreasuryForecastService
{
    /**
     * Forecast organization liquidity and reserve health for the next N days.
     */
    public function forecast(Organization $org, Wallet $wallet, int $daysAhead = 30, float $expectedInflow = 0.00): TreasuryForecast
    {
        $currentBalance = (float) $wallet->balance;
        $minimumReserve = (float) $org->minimum_reserve;
        $endDate = Carbon::now()->addDays($daysAhead);

        // Fetch upcoming pending invoices due within the forecast window
        $upcomingInvoices = Invoice::where('organization_id', $org->id)
            ->whereIn('status', ['pending', 'held', 'escalated'])
            ->whereBetween('due_date', [Carbon::today(), $endDate])
            ->orderBy('due_date')
            ->get();

        $totalObligations = (float) $upcomingInvoices->sum('amount');
        $projectedBalance = $currentBalance + $expectedInflow - $totalObligations;

        // Determine health status
        $healthStatus = 'SAFE';
        if ($projectedBalance < $minimumReserve) {
            $healthStatus = 'CRITICAL';
        } elseif ($projectedBalance <= ($minimumReserve * 1.15)) {
            $healthStatus = 'WARNING';
        }

        // Trace day-by-day cash flow to detect breach timing
        $runningBalance = $currentBalance;
        $daysUntilBreach = null;

        for ($day = 1; $day <= $daysAhead; $day++) {
            $checkDate = Carbon::today()->addDays($day);
            $dayInvoicesAmount = (float) $upcomingInvoices
                ->filter(fn (Invoice $inv) => $inv->due_date->isSameDay($checkDate))
                ->sum('amount');

            $runningBalance -= $dayInvoicesAmount;

            if ($runningBalance < $minimumReserve && $daysUntilBreach === null) {
                $daysUntilBreach = $day;
            }
        }

        return new TreasuryForecast(
            currentBalance: $currentBalance,
            expectedInflow: $expectedInflow,
            scheduledObligations: $totalObligations,
            projectedBalance: $projectedBalance,
            minimumReserve: $minimumReserve,
            healthStatus: $healthStatus,
            daysUntilReserveBreach: $daysUntilBreach,
            breakdown: [
                'invoices_count' => $upcomingInvoices->count(),
                'forecast_window_days' => $daysAhead,
                'reserve_buffer' => max(0.00, $projectedBalance - $minimumReserve),
            ]
        );
    }
}
