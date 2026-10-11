<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Finance\Widgets\ApprovalInboxWidget;
use App\Filament\Finance\Widgets\ArcSettlementWidget;
use App\Filament\Finance\Widgets\AutonomyLaneWidget;
use App\Filament\Finance\Widgets\BudgetCapacityWidget;
use App\Filament\Finance\Widgets\BudgetHeadroomChart;
use App\Filament\Finance\Widgets\CollectionsOverviewWidget;
use App\Filament\Finance\Widgets\CollectionsTrendChart;
use App\Filament\Finance\Widgets\EvidenceTimelineWidget;
use App\Filament\Finance\Widgets\OperationsHealthWidget;
use App\Filament\Finance\Widgets\PaymentDecisionChart;
use App\Filament\Finance\Widgets\WorkflowRunStateChart;
use App\Filament\Finance\Widgets\WorkflowRunWidget;
use App\Models\CollectionBatch;
use App\Models\FinanceWorkflowRun;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\LeptonTreasuryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * The finance workspace described in the pilot contract.
 *
 * One panel for cashier, accounting and finance operations: what money was
 * reported, what has been independently reviewed, what is planned, what is
 * waiting on a person, what the Arc rail actually observes, and whether the
 * machinery behind all of it is running. Money figures stay exact and every
 * stage that is not delivered is named rather than implied.
 */
class FinanceDashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::PresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Financial Operations';

    protected static ?string $navigationLabel = 'Overview';

    protected static ?string $title = 'Finance supervisor';

    protected static ?int $navigationSort = 0;

    protected static bool $isDiscovered = false;

    #[\Override]
    public function getSubheading(): ?string
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return 'Institution context unavailable — run eduflow:install. Data is never selected by row order.';
        }

        $status = app(LeptonTreasuryService::class)->status($institution->primaryWallet());

        return sprintf(
            '%s · %s driver on %s %s%s · no submitted payment exists in this build',
            $institution->name,
            $status['driver'],
            strtoupper((string) $status['chain']),
            (string) $status['chain_id'],
            $status['is_fake'] ? ' · FAKE DRIVER, simulation only' : '',
        );
    }

    #[\Override]
    public static function canAccess(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        $gate = Gate::forUser($user);
        if ($gate->allows('viewAny', FinanceWorkflowRun::class)) {
            return true;
        }
        if ($gate->allows('create', CollectionBatch::class)) {
            return true;
        }

        return $gate->allows('create', PaymentIntent::class);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('captureCollections')
                ->label('Record receipts')
                ->icon(Heroicon::Banknotes)
                ->color('primary')
                ->url(fn (): string => Collections::getUrl(panel: 'finance')),

            Action::make('reviewProposals')
                ->label('Review proposals')
                ->icon(Heroicon::ClipboardDocumentList)
                ->color('gray')
                ->url(fn (): string => FinanceSupervisor::getUrl(panel: 'finance')),

            Action::make('reviewPayments')
                ->label('Review payments')
                ->icon(Heroicon::ClipboardDocumentCheck)
                ->color('gray')
                ->url(fn (): string => PaymentReviews::getUrl(panel: 'finance')),
        ];
    }

    /**
     * @return array<class-string<Widget>>
     */
    #[\Override]
    public function getWidgets(): array
    {
        return [
            ApprovalInboxWidget::class,
            CollectionsOverviewWidget::class,
            CollectionsTrendChart::class,
            BudgetCapacityWidget::class,
            BudgetHeadroomChart::class,
            ArcSettlementWidget::class,
            WorkflowRunWidget::class,
            WorkflowRunStateChart::class,
            AutonomyLaneWidget::class,
            EvidenceTimelineWidget::class,
            PaymentDecisionChart::class,
            OperationsHealthWidget::class,
        ];
    }

    /** @return int|array<string, int|null> */
    #[\Override]
    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2, 'xl' => 2];
    }
}
