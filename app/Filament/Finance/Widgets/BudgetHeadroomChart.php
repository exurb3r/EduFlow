<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\DTOs\Money;
use App\Filament\Pages\FinanceSupervisor;
use App\Filament\Support\LinkedHeading;
use App\Models\BudgetSnapshot;
use App\Services\InstallationInstitution;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Approved allocation against realized cash headroom, per department.
 *
 * Two series side by side because they answer different questions and can
 * disagree: an allocation is an approved permission to spend, while realized
 * cash is money that has actually been collected and survives reserve and
 * restriction. A department with headroom but no cash is the case a
 * supervisor most needs to see, and it disappears if you plot one number.
 */
class BudgetHeadroomChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Approved allocation against realized cash headroom';

    protected ?string $description = 'Latest captured budget evidence per department. Allocation is not cash: an approved permission to spend and collected money are plotted separately, and neither is the Circle wallet balance.';

    protected ?string $emptyStateHeading = 'No budget evidence captured yet';

    protected ?string $emptyStateDescription = 'A budget snapshot appears here once staff attest the approved allocation and the cash position behind it.';

    #[\Override]
    protected function getType(): string
    {
        return 'bar';
    }

    #[\Override]
    public function getHeading(): string|Htmlable|null
    {
        return LinkedHeading::make($this->heading, FinanceSupervisor::getUrl(panel: 'finance'));
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    protected function getData(): array
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return [];
        }

        $snapshots = BudgetSnapshot::query()
            ->where('organization_id', $institution->id)
            ->whereIn('id', BudgetSnapshot::query()
                ->selectRaw('MAX(id)')
                ->where('organization_id', $institution->id)
                ->groupBy('budget_id'))
            ->get();

        $rows = [];

        foreach ($snapshots as $snapshot) {
            // Corrupt evidence has no headroom; the table beside this chart
            // reports it as a digest mismatch, and it must not be plotted as
            // a zero here.
            if (! $snapshot->hasValidSnapshot()) {
                continue;
            }

            $headroom = $snapshot->headroom();
            $department = (string) ($snapshot->snapshot['department']['name'] ?? 'Unnamed');
            $currency = $snapshot->currency;

            $rows[] = [
                'label' => $currency.' · '.$department,
                'allocation' => Money::toChartValue(data_get($snapshot->snapshot, 'amounts.allocation'), $currency),
                'cash' => Money::toChartValue($headroom['cash_minor_units'], $currency),
            ];
        }

        if ($rows === []) {
            return [];
        }

        return [
            'labels' => array_column($rows, 'label'),
            'datasets' => [
                [
                    'label' => 'Approved allocation',
                    'backgroundColor' => '#38bdf8',
                    'borderRadius' => 2,
                    'data' => array_column($rows, 'allocation'),
                ],
                [
                    'label' => 'Realized cash headroom',
                    'backgroundColor' => '#10b981',
                    'borderRadius' => 2,
                    'data' => array_column($rows, 'cash'),
                ],
            ],
        ];
    }
}
