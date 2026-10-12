<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\Filament\Pages\FinanceSupervisor;
use App\Filament\Support\LinkedHeading;
use App\Models\FinanceWorkflowRun;
use App\Services\InstallationInstitution;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Durable background work by run state.
 *
 * The chart exists to make one distinction legible: a system with no queued,
 * running or waiting work and a system with a dead worker look identical on
 * every other panel. Blocked and failed are coloured to be found.
 */
class WorkflowRunStateChart extends ChartWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Background work by run state';

    protected ?string $description = 'Counted from persisted run records. Seeing a run is not seeing a payment: no run in this system submits, authorizes or settles a transfer.';

    protected ?string $emptyStateHeading = 'No background runs recorded';

    protected ?string $emptyStateDescription = 'A reviewed collection or a scheduled due scan creates the first run. Nothing here runs until the background runtime is enabled.';

    /** @var array<string, string> */
    protected array $colors = [
        'Queued' => '#a1a1aa',
        'Running' => '#38bdf8',
        'Waiting for review' => '#f59e0b',
        'Completed' => '#10b981',
        'Blocked' => '#fb923c',
        'Failed' => '#ef4444',
        'Paused' => '#71717a',
    ];

    #[\Override]
    protected function getType(): string
    {
        return 'doughnut';
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

        $counts = FinanceWorkflowRun::query()
            ->where('organization_id', $institution->id)
            ->selectRaw('state, COUNT(*) AS total')
            ->groupBy('state')
            ->pluck('total', 'state');

        $labels = [];
        $values = [];
        $colors = [];

        foreach ($this->colors as $label => $color) {
            $state = match ($label) {
                'Waiting for review' => 'waiting_for_review',
                default => strtolower(str_replace(' ', '_', $label)),
            };

            $total = (int) ($counts[$state] ?? 0);

            if ($total === 0) {
                continue;
            }

            $labels[] = $label;
            $values[] = $total;
            $colors[] = $color;
        }

        if ($values === []) {
            return [];
        }

        return [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Runs',
                'data' => $values,
                'backgroundColor' => $colors,
                'borderWidth' => 0,
            ]],
        ];
    }
}
