<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use AlizHarb\ActivityLog\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Support\LinkedHeading;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\FinanceWorkflowRun;
use App\Models\InvoiceVersion;
use App\Models\PaymentAuthorization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Services\InstallationInstitution;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Whether this installation is actually being used, per day.
 *
 * An administrator of a self-hosted deployment cannot see activity from the
 * finance panel's evidence chain, yet "is the school running this" is the
 * first operational question. Counting records rather than money keeps the
 * answer available even where no budget has been captured.
 */
class AdoptionActivityChart extends ChartWidget
{
    protected static ?int $sort = -2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Installation activity';

    protected ?string $description = 'Finance records created per day over the last 14 days. Adoption, not money moved.';

    protected ?string $emptyStateHeading = 'No activity recorded yet';

    protected ?string $emptyStateDescription = 'Receipts, budget evidence and payment reviews land here once the school starts using the installation.';

    protected ?string $maxHeight = '320px';

    #[\Override]
    protected function getType(): string
    {
        return 'bar';
    }

    #[\Override]
    public function getHeading(): string|Htmlable|null
    {
        return LinkedHeading::make($this->heading, ActivityLogResource::getUrl('index'));
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
                    'ticks' => [
                        'precision' => 0,
                    ],
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

        $since = now()->subDays(13)->startOfDay();
        $institutionId = $institution->id;

        $series = [
            'Collection batches' => [CollectionBatch::class, '#f59e0b'],
            'Budget snapshots' => [BudgetSnapshot::class, '#38bdf8'],
            'Invoice evidence' => [InvoiceVersion::class, '#a78bfa'],
            'Payment drafts' => [PaymentIntent::class, '#2dd4bf'],
            'Reservations held' => [PaymentReservation::class, '#fb923c'],
            'Authorizations' => [PaymentAuthorization::class, '#10b981'],
            'Background runs' => [FinanceWorkflowRun::class, '#94a3b8'],
        ];

        $datasets = [];

        foreach ($series as $label => [$model, $color]) {
            /** @var class-string<Model> $model */
            $days = $model::query()
                ->where('organization_id', $institutionId)
                ->where('created_at', '>=', $since)
                ->selectRaw('DATE(created_at) AS day, COUNT(*) AS total')
                ->groupBy('day')
                ->pluck('total', 'day');

            $datasets[] = [
                'label' => $label,
                'backgroundColor' => $color,
                'borderRadius' => 2,
                'data' => $this->window($days),
            ];
        }

        $total = array_sum(array_map(static fn (array $dataset): int => array_sum($dataset['data']), $datasets));

        if ($total <= 0) {
            return [];
        }

        return [
            'labels' => array_map(
                static fn (string $day): string => date('M j', strtotime($day)),
                $this->windowTimestamps(),
            ),
            'datasets' => $datasets,
        ];
    }

    /**
     * A fixed 14-day window with empty days kept, so a gap reads as a gap
     * rather than as an absent bar that looks like no data.
     *
     * @param  Collection<string, mixed>  $days
     * @return list<int>
     */
    private function window(mixed $days): array
    {
        $window = [];

        foreach (array_values($this->windowTimestamps()) as $day) {
            $window[] = (int) ($days[$day] ?? 0);
        }

        return $window;
    }

    /** @return array<int, string> Timestamp keyed by Y-m-d, oldest first. */
    private function windowTimestamps(): array
    {
        $window = [];

        for ($offset = 13; $offset >= 0; $offset--) {
            $day = now()->subDays($offset);
            $window[$day->getTimestamp()] = $day->toDateString();
        }

        return $window;
    }
}
