<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Models\Wallet;
use App\Services\InstallationInstitution;
use App\Services\InvoiceSettlement;
use App\Services\LeptonTreasuryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Throwable;
use UnitEnum;

class TreasuryWallet extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Wallet;

    protected static string|UnitEnum|null $navigationGroup = 'Finance records';

    protected static ?string $navigationLabel = 'Treasury Wallet';

    protected static ?string $title = 'Treasury Wallet';

    protected static ?string $slug = 'treasury-wallet';

    protected static ?int $navigationSort = 1;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.pages.treasury-wallet';

    /**
     * @var array{
     *     chain:string, chain_id:int, block:?int, rpc_host:?string, driver:string,
     *     live_available:bool, onchain_balance:?float, ledger_balance:float,
     *     drift:?float, in_sync:?bool, treasury_address:?string,
     *     address_url:?string, is_fake:bool, error:?string
     * }|null
     */
    protected ?array $memoizedStatus = null;

    #[\Override]
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' ? 'Finance records' : 'Financial Operations';
    }

    #[\Override]
    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        if (Filament::getCurrentPanel()?->getId() === 'admin') {
            return $user->hasRole('super_admin');
        }

        return $user->hasAnyRole(['super_admin', 'admin', 'finance_officer']);
    }

    #[\Override]
    public function getSubheading(): ?string
    {
        $institution = $this->institution();

        if (! $institution instanceof Organization) {
            return 'Institution context unavailable — configure EDUFLOW_INSTITUTION_ID or run php artisan eduflow:install.';
        }

        $status = $this->treasuryStatus();

        return sprintf(
            '%s · %s driver on %s (Chain ID %s) · %s',
            $institution->name,
            $status['driver'],
            strtoupper((string) $status['chain']),
            (string) $status['chain_id'],
            $status['is_fake'] ? 'Simulation only (Fake Gateway)' : 'Live Arc Settlement',
        );
    }

    public function institution(): ?Organization
    {
        return app(InstallationInstitution::class)->current();
    }

    public function wallet(): ?Wallet
    {
        return $this->institution()?->primaryWallet();
    }

    /**
     * @return array{
     *     chain:string, chain_id:int, block:?int, rpc_host:?string, driver:string,
     *     live_available:bool, onchain_balance:?float, ledger_balance:float,
     *     drift:?float, in_sync:?bool, treasury_address:?string,
     *     address_url:?string, is_fake:bool, error:?string
     * }
     */
    public function treasuryStatus(): array
    {
        if ($this->memoizedStatus !== null) {
            return $this->memoizedStatus;
        }

        return $this->memoizedStatus = app(LeptonTreasuryService::class)->status($this->wallet());
    }

    /**
     * @return array{
     *     total_amount: float,
     *     count: int,
     *     overdue_count: int,
     *     overdue_amount: float
     * }
     */
    public function unpaidInvoicesSummary(): array
    {
        $institution = $this->institution();

        if (! $institution instanceof Organization) {
            return [
                'total_amount' => 0.0,
                'count' => 0,
                'overdue_count' => 0,
                'overdue_amount' => 0.0,
            ];
        }

        $unpaidQuery = Invoice::query()
            ->where('organization_id', $institution->id)
            ->whereNotIn('status', ['paid', 'auto_paid', 'rejected']);

        $totalAmount = (float) (clone $unpaidQuery)->sum('amount');
        $count = (clone $unpaidQuery)->count();

        $overdueQuery = (clone $unpaidQuery)->where('due_date', '<', Carbon::today());
        $overdueCount = (clone $overdueQuery)->count();
        $overdueAmount = (float) (clone $overdueQuery)->sum('amount');

        return [
            'total_amount' => $totalAmount,
            'count' => $count,
            'overdue_count' => $overdueCount,
            'overdue_amount' => $overdueAmount,
        ];
    }

    public function netLiquidity(): ?float
    {
        $status = $this->treasuryStatus();

        if (! $status['live_available'] || $status['onchain_balance'] === null) {
            return null;
        }

        $unpaid = $this->unpaidInvoicesSummary()['total_amount'];

        return (float) $status['onchain_balance'] - $unpaid;
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        $wallet = $this->wallet();
        $status = $this->treasuryStatus();

        $needsAdoption = $wallet instanceof Wallet
            && $status['treasury_address'] !== null
            && strcasecmp((string) $status['treasury_address'], (string) $wallet->address) !== 0;

        return [
            Action::make('openExplorer')
                ->label('ArcScan')
                ->icon(Heroicon::ArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (): bool => ! empty($status['address_url']))
                ->url(fn (): ?string => $status['address_url'])
                ->openUrlInNewTab(),

            Action::make('adoptWallet')
                ->label('Use agent wallet')
                ->icon(Heroicon::Link)
                ->color('primary')
                ->visible(fn (): bool => $needsAdoption)
                ->requiresConfirmation()
                ->modalHeading('Point the treasury at your Lepton agent wallet?')
                ->modalDescription('Updates the organization wallet address to the configured LEPTON_TREASURY_ADDRESS. The ledger balance is untouched until you sync from the chain.')
                ->action(function () use ($wallet): void {
                    if (! $wallet instanceof Wallet) {
                        return;
                    }

                    try {
                        $this->memoizedStatus = null;
                        $changed = app(LeptonTreasuryService::class)->adoptConfiguredWallet($wallet);

                        Notification::make()
                            ->title($changed ? 'Treasury wallet updated' : 'Already using that wallet')
                            ->body($changed ? 'Payments now originate from '.$wallet->fresh()?->address : null)
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        report($e);

                        Notification::make()->title('Could not update treasury')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('syncBalance')
                ->label('Sync from chain')
                ->icon(Heroicon::ArrowPath)
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Overwrite the ledger with the on-chain balance?')
                ->modalDescription('Reads the live '.strtoupper((string) $status['chain']).' balance through Lepton and writes it to the EduFlow ledger.')
                ->action(function () use ($wallet): void {
                    if (! $wallet instanceof Wallet) {
                        Notification::make()->title('No treasury wallet found.')->danger()->send();

                        return;
                    }

                    try {
                        $this->memoizedStatus = null;
                        $live = app(LeptonTreasuryService::class)->syncBalance($wallet);

                        if ($live === null) {
                            Notification::make()
                                ->title('Sync failed')
                                ->body('Could not read a live balance. Confirm the Lepton CLI is authenticated.')
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Treasury synced')
                            ->body('Ledger balance set to '.number_format($live, 2).' USDC from the live chain.')
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        report($e);

                        Notification::make()->title('Could not sync balance')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $institution = $this->institution();
        $institutionId = $institution instanceof Organization ? $institution->id : 0;

        return $table
            ->query(
                Invoice::query()
                    ->where('organization_id', $institutionId)
                    ->whereNotIn('status', ['paid', 'auto_paid', 'rejected'])
                    ->with(['vendor', 'budget'])
            )
            ->heading('Pending obligations (Invoices to pay)')
            ->description('Invoices requiring payment from the treasury. Settling these will draw down available USDC.')
            ->emptyStateHeading('No pending obligations')
            ->emptyStateDescription('All recorded invoices are currently paid or settled.')
            ->emptyStateIcon(Heroicon::CheckCircle)
            ->defaultSort('due_date', 'asc')
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference #')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('vendor.name')
                    ->label('Vendor')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' USDC')
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date()
                    ->sortable()
                    ->description(function (Invoice $record): ?string {
                        if ($record->due_date->isPast()) {
                            return 'Overdue by '.$record->due_date->diffForHumans(['parts' => 1]);
                        }

                        return null;
                    }),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'escalated' => 'danger',
                        'held' => 'warning',
                        default => 'info',
                    })
                    ->sortable(),

                TextColumn::make('settlement_proof')
                    ->label('Chain Proof')
                    ->badge()
                    ->getStateUsing(function (Invoice $record): string {
                        if (! in_array($record->status, ['auto_paid', 'paid'], true)) {
                            return 'Unsettled';
                        }

                        return match (InvoiceSettlement::verdictFor($record)) {
                            'verified' => 'Verified on Arc',
                            'unsupported' => 'Receipt invalid',
                            default => 'Unverified',
                        };
                    })
                    ->color(function (Invoice $record): string {
                        if (! in_array($record->status, ['auto_paid', 'paid'], true)) {
                            return 'gray';
                        }

                        return match (InvoiceSettlement::verdictFor($record)) {
                            'verified' => 'success',
                            'unsupported' => 'danger',
                            default => 'info',
                        };
                    }),

                TextColumn::make('budget.name')
                    ->label('Budget')
                    ->placeholder('Unbudgeted')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'escalated' => 'Escalated',
                        'held' => 'Held',
                    ]),

                SelectFilter::make('vendor')
                    ->relationship('vendor', 'name'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::Eye)
                    ->url(fn (Invoice $record): string => InvoiceResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
