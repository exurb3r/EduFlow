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
use Illuminate\Database\Eloquent\Builder;

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

    // Paired with CollectionsTrendChart in the overview grid, so a half-width
    // cell rather than a full-width band the chart would only partly fill.
    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Approved allocation against realized cash headroom';

    protected ?string $description = 'Allocation is not cash: permission to spend and collected money are plotted separately.';

    protected ?string $emptyStateHeading = 'No budget evidence captured yet';

    protected ?string $emptyStateDescription = 'A budget snapshot appears here once staff attest the approved allocation and the cash position behind it.';

    protected ?string $maxHeight = '320px';

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
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                ],
            ],
        ];
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
            ->whereDoesntHave('workflowRuns', fn (Builder $query): Builder => $query->where('state', 'paused'))
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
