<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\DTOs\Money;
use App\Filament\Pages\Collections;
use App\Filament\Support\LinkedHeading;
use App\Models\CollectionBatch;
use App\Services\InstallationInstitution;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Reported receipts over the collection interval, split by review state.
 *
 * Stacked on purpose: the height of a bar is what staff recorded, and the
 * segments are how much of it can actually fund a plan. Only the approved
 * segment may be spent, so a chart that summed everything into one series
 * would overstate available money at a glance.
 *
 * Bars are keyed by currency as well as by day. Adding two currencies into
 * one stack would be a conversion this application has no authority to make.
 */
class CollectionsTrendChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Reported collections by review state';

    protected ?string $description = 'Last 14 collection intervals. Restricted cash is deducted before any plan, so it is context here rather than spendable money. Reversal and suspense tracking are not implemented, so reversed money is not yet separable.';

    protected ?string $emptyStateHeading = 'No collection batches recorded yet';

    protected ?string $emptyStateDescription = 'A cashier-entered batch appears here once recorded. Unreviewed batches are reported money, not cash.';

    /** @var array<string, string> */
    protected array $colors = [
        'Reviewed — can fund a plan' => '#10b981',
        'Awaiting independent review' => '#f59e0b',
        'Held' => '#a1a1aa',
        'Rejected' => '#ef4444',
    ];

    #[\Override]
    protected function getType(): string
    {
        return 'bar';
    }

    #[\Override]
    public function getHeading(): string|Htmlable|null
    {
        return LinkedHeading::make($this->heading, Collections::getUrl(panel: 'finance'));
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

        $batches = CollectionBatch::query()
            ->where('organization_id', $institution->id)
            ->where('collected_until', '>=', now()->subDays(13)->startOfDay())
            ->with('reviews')
            ->get();

        if ($batches->isEmpty()) {
            return [];
        }

        /** @var array<string, array{day: string, currency: string, segments: array<string, int>}> $buckets */
        $buckets = [];

        foreach ($batches as $batch) {
            $day = $batch->collected_until->toDateString();
            $key = $batch->currency.'|'.$day;

            // A batch carries at most one review, so the latest decision is
            // the only one that can apply. Unreviewed stays unreviewed.
            $segment = match ($batch->reviews->last()?->decision) {
                'approve_receipts' => 'Reviewed — can fund a plan',
                'hold' => 'Held',
                'reject' => 'Rejected',
                default => 'Awaiting independent review',
            };

            $buckets[$key] ??= ['day' => $day, 'currency' => $batch->currency, 'segments' => []];
            $buckets[$key]['segments'][$segment] = ($buckets[$key]['segments'][$segment] ?? 0) + $batch->received_minor_units;
        }

        ksort($buckets);
        $rows = array_values($buckets);

        $datasets = [];

        foreach ($this->colors as $label => $color) {
            $datasets[] = [
                'label' => $label,
                'backgroundColor' => $color,
                'borderRadius' => 2,
                'data' => array_map(
                    fn (array $bucket): float => Money::toChartValue($bucket['segments'][$label] ?? null, $bucket['currency']),
                    $rows,
                ),
            ];
        }

        return [
            'labels' => array_map(
                fn (array $bucket): string => $bucket['currency'].' · '.$bucket['day'],
                $rows,
            ),
            'datasets' => $datasets,
        ];
    }
}
