<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\DTOs\Money;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\FinanceAffordability;
use App\Services\InstallationInstitution;
use App\Services\InvoiceSettlement;
use App\Services\LeptonTreasuryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;
use UnitEnum;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

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

    /** @var array<string, mixed>|null */
    protected ?array $memoizedAffordability = null;

    #[\Override]
    public static function getNavigationLabel(): string
    {
        return Filament::getCurrentPanel()?->getId() === 'finance' ? 'Bills & funding' : 'Treasury Wallet';
    }

    #[\Override]
    public function getTitle(): string
    {
        return 'Bills & funding';
    }

    /** @return array<string, mixed> */
    public function affordability(): array
    {
        return $this->memoizedAffordability ??= app(FinanceAffordability::class)->summarize($this->actor());
    }

    private function actor(): User
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            abort(403);
        }

        return $actor;
    }

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
            return $user->hasVerifiedEmail() && $user->hasRole('super_admin');
        }

        return $user->hasVerifiedEmail() && $user->hasAnyRole(['super_admin', 'admin', 'finance_officer']);
    }

    #[\Override]
    public function getSubheading(): ?string
    {
        $institution = $this->institution();

        if (! $institution instanceof Organization) {
            return 'Institution context unavailable — configure EDUFLOW_INSTITUTION_ID or run php artisan app:install.';
        }

        return $institution->name.' · See what is owed, what cash is observed, and what still needs review.';
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
        $status = ['treasury_address' => config('lepton.arc.treasury'), 'chain' => config('lepton.arc.chain', 'ARC-TESTNET'),
            'address_url' => null];
        try {
            $address = config('lepton.arc.treasury');
            $status['address_url'] = is_string($address) ? app(ArcNetworkGateway::class)->addressExplorerUrl($address) : null;
        } catch (Throwable) {
            $status['address_url'] = null;
        }

        $needsAdoption = $wallet instanceof Wallet
            && $status['treasury_address'] !== null
            && strcasecmp((string) $status['treasury_address'], (string) $wallet->address) !== 0;

        return [
            Action::make('refreshFunding')->label('Refresh balance')->icon(Heroicon::ArrowPath)
                ->action(function (): void {
                    $this->memoizedAffordability = app(FinanceAffordability::class)->summarize($this->actor(), fresh: true);
                    Notification::make()->title('Observation refreshed')->body('Read only. Ledger and payment approvals unchanged.')->success()->send();
                }),
            Action::make('institutionPolicy')->label('Institution policy')->icon(Heroicon::BookOpen)
                ->color('gray')
                ->url(fn (): string => route('finance.policies.index')),
            Action::make('allocationAdvice')->label('Agent allocations')->icon(Heroicon::Sparkles)
                ->color('gray')
                ->visible(fn (): bool => $this->actor()->hasAnyRole(['admin', 'super_admin']))
                ->url(fn (): string => route('finance.policy-evaluations.index')),
            Action::make('openExplorer')
                ->label('ArcScan')
                ->icon(Heroicon::ArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (): bool => is_string($status['address_url']) && $status['address_url'] !== '')
                ->url(fn (): ?string => $status['address_url'])
                ->openUrlInNewTab(),

            Action::make('adoptWallet')
                ->label('Use agent wallet')
                ->icon(Heroicon::Link)
                ->color('primary')
                ->visible(fn (): bool => $needsAdoption && $this->actor()->hasRole('super_admin'))
                ->requiresConfirmation()
                ->modalHeading('Point the treasury at your Lepton agent wallet?')
                ->modalDescription('Updates the organization wallet address to the configured LEPTON_TREASURY_ADDRESS. The ledger balance is untouched until you sync from the chain.')
                ->action(function () use ($wallet): void {
                    if (! $wallet instanceof Wallet) {
                        return;
                    }
                    abort_unless($this->actor()->hasRole('super_admin'), 403);

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
                ->visible(fn (): bool => $this->actor()->hasRole('super_admin'))
                ->requiresConfirmation()
                ->modalHeading('Overwrite the ledger with the on-chain balance?')
                ->modalDescription('Reads the live '.strtoupper((string) $status['chain']).' balance through Lepton and writes it to the EduFlow ledger.')
                ->action(function () use ($wallet): void {
                    abort_unless($this->actor()->hasRole('super_admin'), 403);
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
                    ->state(fn (Invoice $record): string => Money::formatExact($this->affordability()['bills'][$record->id]['amount_base_units'] ?? null, 'USDC'))
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

                TextColumn::make('funding_readiness')->label('What blocks payment?')->badge()->wrap()
                    ->state(fn (Invoice $record): string => $this->affordability()['bills'][$record->id]['funding_label'] ?? 'Needs review')
                    ->color(fn (Invoice $record): string => ($this->affordability()['bills'][$record->id]['evidence_approved'] ?? false) ? 'info' : 'warning')
                    ->description(fn (Invoice $record): string => 'Individual comparison only. Shared cash cannot pay every fitting bill twice.'),

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
                Action::make('details')->label('Bill details')->icon(Heroicon::Eye)
                    ->visible(fn (): bool => Filament::getCurrentPanel()?->getId() === 'finance')
                    ->modalHeading('Bill funding evidence')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('reference')->label('Invoice')->state(fn (Invoice $record): string => $record->reference),
                        TextEntry::make('vendor')->label('Vendor')->state(function (Invoice $record): string {
                            $vendor = Vendor::query()->find($record->vendor_id);

                            return $vendor instanceof Vendor ? $vendor->name : 'Missing vendor';
                        }),
                        TextEntry::make('obligation')->label('Exact obligation')->state(fn (Invoice $record): string => Money::formatExact($this->affordability()['bills'][$record->id]['amount_base_units'] ?? null, 'USDC')),
                        TextEntry::make('budget')->label('Budget line')->state(function (Invoice $record): string {
                            $budget = Budget::query()->find($record->budget_id);

                            return $budget instanceof Budget ? $budget->name : 'No budget line';
                        }),
                        TextEntry::make('blocker')->label('What blocks payment?')->state(fn (Invoice $record): string => $this->affordability()['bills'][$record->id]['funding_label'] ?? 'Needs review'),
                        TextEntry::make('next_step')->label('Next step')->state('Review invoice evidence and current receipts, request agent allocation, then obtain exact independent payment approval. This screen cannot pay.'),
                    ]),
                Action::make('view')
                    ->label('View')
                    ->icon(Heroicon::Eye)
                    ->visible(fn (): bool => Filament::getCurrentPanel()?->getId() === 'admin')
                    ->url(fn (Invoice $record): string => InvoiceResource::getUrl('view', ['record' => $record], panel: 'admin')),
            ]);
    }
}
