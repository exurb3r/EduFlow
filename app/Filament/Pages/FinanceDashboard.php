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
use App\Models\FinancePolicyActivation;
use App\Models\FinancePolicyVersion;
use App\Models\FinanceWorkflowRun;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\User;
use App\Services\InstallationInstitution;
use App\Services\LeptonTreasuryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Grid as InfoGrid;
use Filament\Schemas\Components\Section as InfoSection;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Throwable;
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
            '%s · %s driver on %s %s%s · exact payment approval and background settlement remain separate',
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

            ActionGroup::make([
                Action::make('syncRail')
                    ->label('Sync Arc balance')
                    ->icon(Heroicon::ArrowPath)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->hidden(fn (): bool => ! Auth::user() instanceof User || ! Auth::user()->hasRole('super_admin'))
                    ->modalHeading('Synchronize ledger from Arc chain?')
                    ->modalDescription('Polls the configured Lepton Arc network gateway and updates the ledger treasury balance to match.')
                    ->action(function (): void {
                        $institution = app(InstallationInstitution::class)->current();
                        $wallet = $institution?->primaryWallet();

                        if (! $wallet) {
                            Notification::make()->title('No institution wallet configured')->danger()->send();

                            return;
                        }

                        try {
                            $live = app(LeptonTreasuryService::class)->syncBalance($wallet);

                            if ($live === null) {
                                Notification::make()
                                    ->title('Sync failed')
                                    ->body('Could not read a live balance from Lepton Arc gateway.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title('Treasury synchronized')
                                ->body('Ledger balance updated to '.number_format($live, 2).' USDC from chain.')
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            report($e);
                            Notification::make()->title('Sync error')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Action::make('executiveBrief')
                    ->label('Supervisory brief')
                    ->icon(Heroicon::DocumentChartBar)
                    ->color('gray')
                    ->modalHeading('Financial supervisory overview')
                    ->modalDescription('Current status of collections, proposals, active holds and Arc settlement rail.')
                    ->infolist([
                        InfoSection::make('Pending Decisions')
                            ->schema([
                                InfoGrid::make(3)->schema([
                                    TextEntry::make('unreviewed_collections')
                                        ->label('Unreviewed Receipt Batches')
                                        ->badge()
                                        ->color('warning')
                                        ->state(function (): int {
                                            $institution = app(InstallationInstitution::class)->current();

                                            return $institution === null ? 0 : CollectionBatch::query()
                                                ->where('organization_id', $institution->id)
                                                ->whereDoesntHave('reviews')
                                                ->count();
                                        }),
                                    TextEntry::make('waiting_proposals')
                                        ->label('Proposals Awaiting Review')
                                        ->badge()
                                        ->color('warning')
                                        ->state(function (): int {
                                            $institution = app(InstallationInstitution::class)->current();

                                            return $institution === null ? 0 : FinanceWorkflowRun::query()
                                                ->where('organization_id', $institution->id)
                                                ->where('state', 'waiting_for_review')
                                                ->count();
                                        }),
                                    TextEntry::make('reserved_holds')
                                        ->label('Active Payment Holds')
                                        ->badge()
                                        ->color('primary')
                                        ->state(function (): int {
                                            $institution = app(InstallationInstitution::class)->current();

                                            return $institution === null ? 0 : PaymentReservation::query()
                                                ->where('organization_id', $institution->id)
                                                ->where('state', 'held')
                                                ->count();
                                        }),
                                ]),
                            ]),
                        InfoSection::make('Settlement Rail Telemetry')
                            ->schema([
                                InfoGrid::make(3)->schema([
                                    TextEntry::make('rail_driver')
                                        ->label('Lepton Driver')
                                        ->badge()
                                        ->state(function (): string {
                                            $institution = app(InstallationInstitution::class)->current();
                                            $status = app(LeptonTreasuryService::class)->status($institution?->primaryWallet());

                                            return $status['driver'].($status['is_fake'] ? ' (Simulation)' : '');
                                        }),
                                    TextEntry::make('chain_block')
                                        ->label('Target Chain & Block')
                                        ->badge()
                                        ->color('info')
                                        ->state(function (): string {
                                            $institution = app(InstallationInstitution::class)->current();
                                            $status = app(LeptonTreasuryService::class)->status($institution?->primaryWallet());

                                            return strtoupper((string) $status['chain']).' · Block '.number_format((float) ($status['block'] ?? 0));
                                        }),
                                    TextEntry::make('active_policy')
                                        ->label('Active Spending Policy')
                                        ->badge()
                                        ->color('success')
                                        ->state(function (): string {
                                            $institution = app(InstallationInstitution::class)->current();
                                            $activation = $institution === null ? null : FinancePolicyActivation::current($institution->id);
                                            $policyVersion = $activation?->policyVersion;

                                            return $policyVersion instanceof FinancePolicyVersion ? $policyVersion->version : 'No active policy';
                                        }),
                                ]),
                            ]),
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                Action::make('openAdmin')
                    ->label('Admin console')
                    ->icon(Heroicon::AdjustmentsHorizontal)
                    ->color('gray')
                    ->url(fn (): string => Filament::getPanel('admin')->getUrl()),
            ])
                ->label('Supervisory tools')
                ->icon(Heroicon::EllipsisVertical)
                ->color('gray'),
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
