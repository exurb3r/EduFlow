<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\Filament\Pages\PaymentReviews;
use App\Filament\Support\LinkedHeading;
use App\Models\PaymentAuthorization;
use App\Services\InstallationInstitution;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Recorded human payment decisions.
 *
 * Approval-as-is rate, the metric the pilot is judged on, needs the whole
 * denominator: approved, rejected and held together. Showing only approvals
 * would flatter the result, and the plan treats a hold as a distinct outcome
 * rather than a rejection.
 */
class PaymentDecisionChart extends ChartWidget
{
    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Human payment decisions';

    protected ?string $description = 'Every recorded authorization, by decision. An approval is an authorization record only: it expires within five minutes, it requires a fresh step-up check, and it still cannot execute because no executor ships.';

    protected ?string $emptyStateHeading = 'No payment authorization recorded';

    protected ?string $emptyStateDescription = 'Authorization appears once a reserved bill is reviewed by an enrolled reviewer. Nothing can be authorized before a hold exists.';

    /** @var array<string, string> */
    protected array $colors = [
        'Approved' => '#10b981',
        'Rejected' => '#ef4444',
        'Held' => '#f59e0b',
    ];

    #[\Override]
    protected function getType(): string
    {
        return 'bar';
    }

    #[\Override]
    public function getHeading(): string|Htmlable|null
    {
        return LinkedHeading::make($this->heading, PaymentReviews::getUrl(panel: 'finance'));
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

        $counts = PaymentAuthorization::query()
            ->where('organization_id', $institution->id)
            ->selectRaw('decision, COUNT(*) AS total')
            ->groupBy('decision')
            ->pluck('total', 'decision');

        $mapping = [
            'approve_payment' => 'Approved',
            'reject_payment' => 'Rejected',
            'hold_payment' => 'Held',
        ];

        $labels = [];
        $values = [];
        $backgrounds = [];

        foreach ($mapping as $decision => $label) {
            $total = (int) ($counts[$decision] ?? 0);

            if ($total === 0) {
                continue;
            }

            $labels[] = $label;
            $values[] = $total;
            $backgrounds[] = $this->colors[$label];
        }

        if ($values === []) {
            return [];
        }

        return [
            'labels' => $labels,
            'datasets' => [[
                'label' => 'Authorizations',
                'backgroundColor' => $backgrounds,
                'borderRadius' => 2,
                'data' => $values,
            ]],
        ];
    }
}
