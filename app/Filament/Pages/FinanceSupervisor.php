<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\CaptureBudgetSnapshot;
use App\Actions\ReviewFinancePlan;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatchReview;
use App\Models\FinanceWorkflowRun;
use App\Models\InvoiceVersion;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\InstallationInstitution;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use UnitEnum;

/** @property-read array{plans: int, collections: int} $waitingInbox */
class FinanceSupervisor extends Page implements HasTable
{
    use InteractsWithTable;
    use RestrictsFileUploadsToSchemaComponents;

    private const array STATES = [
        'queued' => 'Queued', 'running' => 'Running', 'waiting_for_review' => 'Waiting for review',
        'completed' => 'Completed', 'blocked' => 'Blocked', 'failed' => 'Failed', 'paused' => 'Paused',
    ];

    private const array KINDS = ['budget_plan' => 'Budget plan', 'collection_review' => 'Collection review'];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Financial Operations';

    protected static ?string $navigationLabel = 'Finance Supervisor';

    protected static ?string $title = 'Finance supervisor';

    protected static ?int $navigationSort = 1;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.pages.finance-supervisor';

    #[Locked]
    public ?int $mountedRunId = null;

    #[Locked]
    public string $mountedResultDigest = '';

    /** @var array<string, mixed> */
    #[Locked]
    public array $mountedRunEvidence = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $mountedPlan = [];

    #[\Override]
    public static function canAccess(): bool
    {
        return Auth::user() instanceof User
            && Gate::forUser(Auth::user())->allows('viewAny', FinanceWorkflowRun::class);
    }

    public function table(Table $table): Table
    {
        Gate::forUser(Auth::user())->authorize('viewAny', FinanceWorkflowRun::class);

        return $table
            ->query($this->runsQuery())
            ->heading('Background finance runs')
            ->description('Refreshes every 10 seconds. Inspect worker progress and review exact proposals; this ledger cannot execute payments.')
            ->defaultSort('id', 'desc')->striped()->poll('10s')
            ->columns([
                TextColumn::make('id')->label('Run')->prefix('#')->sortable(),
                TextColumn::make('kind')->label('Workflow')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state): string => self::KINDS[$state] ?? $state),
                TextColumn::make('state')->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success', 'waiting_for_review', 'blocked', 'paused' => 'warning',
                        'failed' => 'danger', 'running' => 'info', default => 'gray',
                    }),
                TextColumn::make('trigger_key')->label('Trigger')->searchable()->limit(38)->wrap()
                    ->tooltip(fn (FinanceWorkflowRun $record): string => $record->trigger_key),
                TextColumn::make('budget_snapshot_id')->label('Budget snapshot')->prefix('#')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('collection_batch_id')->label('Collection batch')->prefix('#')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attempts')->alignEnd(),
                TextColumn::make('heartbeat_at')->label('Heartbeat (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->placeholder('Not started')->sortable(),
                TextColumn::make('next_attempt_at')->label('Next attempt (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->placeholder('Not scheduled')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Created (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_error')->label('Last error')->limit(65)->wrap()->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('state')->options(self::STATES),
                SelectFilter::make('kind')->label('Workflow')->options(self::KINDS),
            ])
            ->recordActions([
                Action::make('viewFinanceRun')->label('Details')->icon(Heroicon::Eye)->color('gray')
                    ->visible(fn (FinanceWorkflowRun $record): bool => Gate::forUser(Auth::user())->allows('view', $record))
                    ->modalHeading('Run evidence and planning result')->modalWidth('6xl')->slideOver()
                    ->mountUsing(function (FinanceWorkflowRun $record, Schema $schema): void {
                        $this->mountRunEvidence($record);
                        $schema->fill();
                    })
                    ->schema(fn (): array => $this->runEvidenceSchema())
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('reviewFinancePlan')->label('Review proposal')->icon(Heroicon::ClipboardDocumentCheck)
                    ->visible(fn (FinanceWorkflowRun $record): bool => Gate::forUser(Auth::user())->allows('review', $record))
                    ->modalHeading('Review exact budget proposal')->modalWidth('6xl')
                    ->modalDescription('Accept, reject or request correction of this exact plan. No funds are reserved and no payment is authorized or executed.')
                    ->modalSubmitActionLabel('Record plan decision')
                    ->mountUsing(function (FinanceWorkflowRun $record, Schema $schema): void {
                        Gate::forUser(Auth::user())->authorize('review', $record);
                        $this->mountRunEvidence($record);
                        $schema->fill();
                    })
                    ->schema(fn (): array => [
                        ...$this->runEvidenceSchema(),
                        Section::make('Proposal decision')->schema([
                            Select::make('decision')->options([
                                'accept_plan' => 'Accept exact plan — no payment authority',
                                'reject_plan' => 'Reject plan',
                                'request_correction' => 'Request correction',
                            ])->in(['accept_plan', 'reject_plan', 'request_correction'])->required(),
                            Textarea::make('reason')->label('Review reason')->required()->maxLength(1000)->rows(3)->columnSpanFull(),
                            Checkbox::make('attestation')
                                ->label('I reviewed this exact proposal, original amounts, USDC valuations and rule checks. My decision does not authorize payment.')
                                ->required()->accepted()->columnSpanFull(),
                        ])->columnSpanFull(),
                    ])
                    ->action(function (FinanceWorkflowRun $record, array $data, Schema $schema, ReviewFinancePlan $review): void {
                        Gate::forUser(Auth::user())->authorize('review', $record);
                        try {
                            if ($this->mountedRunId !== $record->id || $this->mountedResultDigest === '') {
                                throw ValidationException::withMessages(['attestation' => 'Reopen the review and inspect the exact proposal.']);
                            }
                            $review->handle($this->actor(), $record, $this->mountedResultDigest, $data['decision'], $data['reason']);
                        } catch (ValidationException $exception) {
                            $errors = [];
                            foreach ($exception->errors() as $field => $messages) {
                                $errors[$schema->getStatePath().'.'.($field === 'expected_digest' ? 'attestation' : $field)] = $messages;
                            }
                            Notification::make()->title('Plan review refused')->body(collect($exception->errors())->flatten()->first())->danger()->send();

                            throw ValidationException::withMessages($errors);
                        }
                        Notification::make()->title('Plan decision recorded')->body('Exact proposal reviewed. No payment authorized, funds reserved or accounts changed.')->success()->send();
                    }),
            ])
            ->emptyStateHeading('No background finance runs')
            ->emptyStateDescription('Runs appear when backend finance workflows are queued. This page cannot start legacy agents or execute payments.');
    }

    /** @return list<Action> */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('captureBudgetSnapshot')->label('Capture budget planning evidence')->icon(Heroicon::Plus)
                ->visible(fn (): bool => Gate::forUser(Auth::user())->allows('create', BudgetSnapshot::class))
                ->modalHeading('Capture budget planning evidence')->modalWidth('5xl')
                ->modalDescription('Immutable planning snapshot, not funding. Capture does not reserve funds, change accounts or authorize payment. New snapshots enter the background queue only when enabled.')
                ->modalSubmitActionLabel('Capture planning snapshot')
                ->mountUsing(function (Schema $schema): void {
                    Gate::forUser(Auth::user())->authorize('create', BudgetSnapshot::class);
                    $schema->fill(['capture_key' => (string) Str::uuid(), 'collection_review_ids' => []]);
                })
                ->schema(fn (): array => $this->budgetCaptureSchema())
                ->action(function (array $data, Schema $schema, CaptureBudgetSnapshot $capture): void {
                    Gate::forUser(Auth::user())->authorize('create', BudgetSnapshot::class);
                    /** @var Budget $budget */
                    $budget = Budget::query()->where('organization_id', app(InstallationInstitution::class)->require()->id)
                        ->where('status', 'active')->whereKey($data['budget_id'])->firstOrFail();
                    unset($data['budget_id']);
                    try {
                        $snapshot = $capture->handle($this->actor(), $budget, $data);
                    } catch (ValidationException $exception) {
                        $errors = [];
                        foreach ($exception->errors() as $field => $messages) {
                            $errors[$schema->getStatePath().'.'.($field === 'budget' ? 'budget_id' : $field)] = $messages;
                        }
                        Notification::make()->title('Planning capture refused')->body(collect($exception->errors())->flatten()->first())->danger()->send();

                        throw ValidationException::withMessages($errors);
                    }
                    Notification::make()->title('Planning snapshot #'.$snapshot->id.' captured')
                        ->body('Evidence only. No funding, payment authorization or accounts changed. Background processing depends on configuration and worker availability.')
                        ->success()->send();
                }),
        ];
    }

    /** @return list<Component> */
    private function budgetCaptureSchema(): array
    {
        $rules = CaptureBudgetSnapshot::inputRules();
        $amounts = [];
        foreach (BudgetSnapshot::AMOUNT_FIELDS as $field) {
            $amounts[] = TextInput::make($field)->label(Str::headline($field))->inputMode('decimal')->required()->rules($rules[$field]);
        }

        return [
            Section::make('Planning scope and reviewed sources')->schema([
                TextInput::make('capture_key')->label('Capture key (UUID)')->required()->rules($rules['capture_key'])->columnSpanFull(),
                Select::make('budget_id')->label('Active department budget')->searchable()->required()->live()
                    ->options(fn (): array => Budget::query()->where('organization_id', app(InstallationInstitution::class)->current()?->id)
                        ->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())
                    ->rules(fn (): array => ['integer', Rule::exists('budgets', 'id')
                        ->where('organization_id', app(InstallationInstitution::class)->current()?->id)->where('status', 'active')]),
                Select::make('currency')->label('Original currency')->options(array_combine(CurrencyCode::values(), CurrencyCode::values()))
                    ->required()->rules($rules['currency'])->live(),
                TextInput::make('department')->label('Department')->required()->rules($rules['department'])->columnSpanFull(),
                DatePicker::make('period_start')->label('Period start')->format('Y-m-d')->required()->rules($rules['period_start']),
                DatePicker::make('period_end')->label('Period end')->format('Y-m-d')->required()
                    ->rules(array_values(array_diff($rules['period_end'], ['after_or_equal:period_start'])))->afterOrEqual('period_start'),
                TextInput::make('as_of')->label('Evidence as of (UTC)')->placeholder('2026-10-09T08:00:00+00:00')
                    ->required()->rules($rules['as_of'])->regex('/\+00:00$/D'),
                TextInput::make('valid_until')->label('Evidence valid until (UTC)')->placeholder('2026-10-09T12:00:00+00:00')
                    ->required()->rules(array_values(array_diff($rules['valid_until'], ['after:as_of'])))->after('as_of')->regex('/\+00:00$/D'),
                Select::make('bill_ids')->label('Closed bill set')->multiple()->searchable()->required()
                    ->rules($rules['bill_ids'])->nestedRecursiveRules($rules['bill_ids.*'])
                    ->options(fn (Get $get): array => $this->reviewedBillOptions($get))
                    ->helperText('Choose reviewed versions matching this budget, currency, department and period. Held or rejected evidence remains visible to planning checks.')
                    ->columnSpanFull(),
                Select::make('collection_review_ids')->label('Approved receipt reviews')->multiple()->searchable()
                    ->rules($rules['collection_review_ids'])->nestedRecursiveRules($rules['collection_review_ids.*'])
                    ->options(fn (Get $get): array => $this->approvedCollectionOptions($get))
                    ->helperText('Approved receipts in the selected currency, received by the evidence time. Leave empty only when realized receipts are 0.')
                    ->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Exact original-currency amounts')
                ->description('Plain decimal strings; no commas or rounding. Enter every amount, including 0. Realized receipts must equal selected reviewed gross collections; restricted cash must include their restrictions.')
                ->schema($amounts)->columns(['default' => 1, 'md' => 3])->columnSpanFull(),
            Section::make('Source references and attestations')->schema([
                TextInput::make('budget_evidence')->label('Budget evidence reference')->required()->rules($rules['budget_evidence']),
                TextInput::make('cash_evidence')->label('Cash evidence reference')->required()->rules($rules['cash_evidence']),
                TextInput::make('commitment_evidence')->label('Commitment evidence reference')->required()->rules($rules['commitment_evidence']),
                Checkbox::make('commitments_exclude_selected_bills')->label('Other commitments exclude the selected bills.')
                    ->required()->accepted()->rules($rules['commitments_exclude_selected_bills'])->columnSpanFull(),
                Checkbox::make('cash_buckets_disjoint')->label('Cash buckets are disjoint; no funds are counted twice.')
                    ->required()->accepted()->rules($rules['cash_buckets_disjoint'])->columnSpanFull(),
                Checkbox::make('opening_funds_exclude_collections')->label('Opening funds exclude the selected reviewed collections.')
                    ->required()->accepted()->rules($rules['opening_funds_exclude_collections'])->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 3])->columnSpanFull(),
        ];
    }

    /** @return array<int, string> */
    private function reviewedBillOptions(Get $get): array
    {
        if (! $get('budget_id') || ! $get('currency')) {
            return [];
        }
        $institutionId = app(InstallationInstitution::class)->current()?->id;

        /** @var Builder<InvoiceVersion> $query */
        $query = InvoiceVersion::query()->join('invoice_version_reviews', 'invoice_version_reviews.invoice_version_id', '=', 'invoice_versions.id')
            ->where('invoice_versions.organization_id', $institutionId)->where('invoice_version_reviews.organization_id', $institutionId)
            ->where('invoice_versions.budget_id', $get('budget_id'))->where('invoice_versions.source_currency', $get('currency'))
            ->whereIn('invoice_version_reviews.decision', ['approve_evidence', 'reject', 'hold'])
            ->whereColumn('invoice_version_reviews.reviewed_by', '!=', 'invoice_versions.prepared_by')
            ->select(['invoice_versions.*', 'invoice_version_reviews.decision as review_decision'])->orderByDesc('invoice_versions.id');

        return $query->get()->mapWithKeys(fn (InvoiceVersion $bill): array => [$bill->id => 'Version #'.$bill->id.' · Invoice #'.$bill->invoice_id
                .' · '.$this->formatUnits($bill->source_minor_units, $bill->source_currency)
                .' · '.data_get($bill->snapshot, 'department.name').' ('.data_get($bill->snapshot, 'department.period_start')
                .' to '.data_get($bill->snapshot, 'department.period_end').') · '.Str::headline($bill->getAttribute('review_decision'))])->all();
    }

    /** @return array<int, string> */
    private function approvedCollectionOptions(Get $get): array
    {
        if (! $get('currency')) {
            return [];
        }
        $institutionId = app(InstallationInstitution::class)->current()?->id;

        /** @var Builder<CollectionBatchReview> $query */
        $query = CollectionBatchReview::query()->join('collection_batches', 'collection_batches.id', '=', 'collection_batch_reviews.collection_batch_id')
            ->where('collection_batch_reviews.organization_id', $institutionId)->where('collection_batches.organization_id', $institutionId)
            ->where('collection_batch_reviews.decision', 'approve_receipts')->where('collection_batches.currency', $get('currency'))
            ->whereColumn('collection_batch_reviews.reviewed_by', '!=', 'collection_batches.prepared_by')
            ->select(['collection_batch_reviews.id', 'collection_batch_reviews.collection_batch_id', 'collection_batches.source_reference',
                'collection_batches.received_minor_units', 'collection_batches.restricted_minor_units', 'collection_batches.currency'])
            ->orderByDesc('collection_batch_reviews.id');

        return $query->get()->mapWithKeys(fn (CollectionBatchReview $review): array => [$review->id => 'Review #'.$review->id.' · Batch #'.$review->collection_batch_id
                .' · '.$review->getAttribute('source_reference').' · '.$this->formatUnits($review->getAttribute('received_minor_units'), $review->getAttribute('currency'))
                .' received / '.$this->formatUnits($review->getAttribute('restricted_minor_units'), $review->getAttribute('currency')).' restricted'])->all();
    }

    public function operations(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 2])->schema([
                Section::make('Background processing')->icon(Heroicon::ServerStack)
                    ->description('Configuration is not worker-health proof. Check run state, heartbeat and last error.')
                    ->schema([
                        TextEntry::make('background_enabled')->label('Background finance')
                            ->state(fn (): string => config('eduflow.background_finance.enabled', false) ? 'Enabled in configuration' : 'Disabled in configuration')
                            ->badge()->color(fn (): string => config('eduflow.background_finance.enabled', false) ? 'success' : 'gray')->columnSpanFull(),
                        TextEntry::make('queue_connection')->label('Queue connection')->state(fn (): string => config('eduflow.background_finance.queue_connection', 'database')),
                        TextEntry::make('queue_name')->label('Planning queue')->state(fn (): string => config('eduflow.background_finance.queue', 'finance-planning')),
                    ])->columns(['default' => 1, 'md' => 2])->columnSpan(1),
                Section::make('Waiting review inbox')->icon(Heroicon::Inbox)
                    ->description('Institution-wide counts. Policy checks decide who can review each record.')
                    ->schema([
                        TextEntry::make('waiting_plans')->label('Budget proposals waiting')->state(fn (): int => $this->waitingInbox['plans']),
                        TextEntry::make('waiting_collections')->label('Collection workflows waiting')->state(fn (): int => $this->waitingInbox['collections']),
                        Actions::make([
                            Action::make('filterWaitingPlans')->label('Show waiting proposals')->color('gray')
                                ->action(function (): void {
                                    $this->showWaitingPlans();
                                }),
                            Action::make('openCollections')->label('Open collections ledger')->color('gray')
                                ->visible(fn (): bool => Collections::canAccess())->url(fn (): string => Collections::getUrl(panel: 'finance')),
                        ])->columnSpanFull(),
                    ])->columns(['default' => 1, 'md' => 2])->columnSpan(1),
            ])->columnSpanFull(),
        ]);
    }

    /** @return array{plans: int, collections: int} */
    #[Computed]
    public function waitingInbox(): array
    {
        Gate::forUser(Auth::user())->authorize('viewAny', FinanceWorkflowRun::class);
        $counts = $this->runsQuery()->withoutEagerLoads()->where('state', 'waiting_for_review')->select('kind')
            ->selectRaw('COUNT(*) AS total')->groupBy('kind')->pluck('total', 'kind');

        return ['plans' => (int) ($counts['budget_plan'] ?? 0), 'collections' => (int) ($counts['collection_review'] ?? 0)];
    }

    public function showWaitingPlans(): void
    {
        Gate::forUser(Auth::user())->authorize('viewAny', FinanceWorkflowRun::class);
        $this->tableFilters = ['state' => ['value' => 'waiting_for_review'], 'kind' => ['value' => 'budget_plan']];
        $this->updatedTableFilters();
    }

    /** @return Builder<FinanceWorkflowRun> */
    private function runsQuery(): Builder
    {
        /** @var Builder<FinanceWorkflowRun> $query */
        $query = FinanceWorkflowRun::query()->with(['budgetSnapshot', 'collectionBatch'])
            ->where('organization_id', app(InstallationInstitution::class)->current()?->id);

        return $query;
    }

    private function mountRunEvidence(FinanceWorkflowRun $run): void
    {
        Gate::forUser(Auth::user())->authorize('view', $run);
        $this->mountedRunId = $run->id;
        $this->mountedResultDigest = $run->result_digest ?? '';
        $this->mountedPlan = $run->result ?? [];
        $intact = $run->result !== null && $run->result_digest !== null
            && hash_equals($run->result_digest, PaymentIntent::digest($run->result));
        $this->mountedRunEvidence = [
            'id' => $run->id, 'organization_id' => $run->organization_id, 'trigger_key' => $run->trigger_key,
            'kind' => self::KINDS[$run->kind] ?? $run->kind, 'state' => self::STATES[$run->state] ?? $run->state,
            'source_digest' => $run->source_digest, 'result_digest' => $run->result_digest,
            'budget_snapshot_id' => $run->budget_snapshot_id, 'collection_batch_id' => $run->collection_batch_id,
            'attempts' => $run->attempts, 'last_error' => $run->last_error,
            'heartbeat_at' => $run->heartbeat_at?->copy()->utc()->toIso8601String(),
            'next_attempt_at' => $run->next_attempt_at?->copy()->utc()->toIso8601String(),
            'created_at' => $run->created_at?->copy()->utc()->toIso8601String(),
            'integrity' => $run->result === null ? 'No result recorded' : ($intact ? 'Intact result digest' : 'Result integrity failed — review cannot proceed'),
        ];
    }

    /** @return list<Component> */
    private function runEvidenceSchema(): array
    {
        return [
            Section::make('Run identity and worker status')->schema([
                $this->runEntry('id', 'Run ID'), $this->runEntry('organization_id', 'Institution ID'),
                $this->runEntry('kind', 'Workflow'), $this->runEntry('state', 'State'),
                $this->runEntry('budget_snapshot_id', 'Budget snapshot ID'), $this->runEntry('collection_batch_id', 'Collection batch ID'),
                $this->runEntry('attempts', 'Attempts'), $this->runEntry('heartbeat_at', 'Heartbeat (UTC)'),
                $this->runEntry('next_attempt_at', 'Next attempt (UTC)'), $this->runEntry('created_at', 'Created (UTC)'),
                $this->runEntry('last_error', 'Last error')->columnSpanFull(),
                $this->runEntry('trigger_key', 'Trigger key')->copyable()->columnSpanFull(),
                $this->runEntry('source_digest', 'Source digest')->copyable()->columnSpanFull(),
                TextEntry::make('expected_digest')->label('Mounted result digest')
                    ->state(fn (): string => $this->mountedResultDigest)->wrap()->copyable()->columnSpanFull(),
                $this->runEntry('integrity', 'Result integrity')->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Exact budget proposal')->visible(fn (): bool => ($this->mountedRunEvidence['kind'] ?? null) === self::KINDS['budget_plan'] && $this->mountedPlan !== [])
                ->description('Original-currency amounts and USDC valuations are distinct. Suggestions are not funded payment instructions.')
                ->schema([
                    $this->planEntry('department.name', 'Department'), $this->planEntry('currency', 'Original currency'),
                    $this->planEntry('snapshot_id', 'Proposal source snapshot ID'),
                    $this->planEntry('snapshot_digest', 'Proposal source snapshot digest')->copyable()->columnSpanFull(),
                    $this->planEntry('as_of', 'Evidence as of'), $this->planEntry('valid_until', 'Evidence valid until'),
                    $this->planMoneyEntry('headroom.budget_minor_units', 'Initial budget headroom'),
                    $this->planMoneyEntry('headroom.cash_minor_units', 'Initial realized cash headroom'),
                    $this->planMoneyEntry('remaining_budget_minor_units', 'Remaining budget headroom'),
                    $this->planMoneyEntry('remaining_cash_minor_units', 'Remaining realized cash headroom'),
                    TextEntry::make('suggested_usdc_valuation')->label('Suggested USDC valuation — not settlement funding')
                        ->state(fn (): string => $this->formatUnits($this->mountedPlan['suggested_usdc_valuation_base_units'] ?? null, 'USDC'))->columnSpanFull(),
                    $this->planEntry('collection_evidence.mode', 'Collection evidence mode'),
                    $this->planMoneyEntry('collection_evidence.received_minor_units', 'Realized receipts in source evidence'),
                    $this->planEntry('collection_evidence.review_ids', 'Bound collection review IDs')->listWithLineBreaks(),
                    IconEntry::make('opening_funds_exclude_collections')->label('Opening funds exclude counted collections')
                        ->state(fn (): mixed => data_get($this->mountedPlan, 'collection_evidence.opening_funds_exclude_collections'))->boolean(),
                    RepeatableEntry::make('planned_bills')->label('Closed bill set and cumulative rule checks')
                        ->state(fn (): array => $this->planBills())
                        ->schema([
                            TextEntry::make('invoice_version_id')->label('Invoice version ID'), TextEntry::make('invoice_id')->label('Invoice ID'),
                            TextEntry::make('source_amount')->label('Original amount'), TextEntry::make('valuation_amount')->label('USDC valuation'),
                            TextEntry::make('decision')->label('Planning decision')->badge()->color('gray')->columnSpanFull(),
                            IconEntry::make('checks.evidence_intact')->label('Evidence intact')->boolean(),
                            IconEntry::make('checks.evidence_approved')->label('Evidence approved')->boolean(),
                            IconEntry::make('checks.allocation_available')->label('Allocation available')->boolean(),
                            IconEntry::make('checks.realized_cash_available')->label('Realized cash available')->boolean(),
                            TextEntry::make('remaining_budget')->label('Remaining budget after this bill'),
                            TextEntry::make('remaining_cash')->label('Remaining cash after this bill'),
                        ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Authority boundary')->description('Collection and plan reviews do not verify bank balances or Arc funding. No execution controls exist on this page.')
                ->schema([
                    TextEntry::make('authority')->hiddenLabel()->state('Planning and evidence review only. No payment authorization, fund reservation, cash posting or legacy agent execution.'),
                ])->columnSpanFull(),
        ];
    }

    private function runEntry(string $key, string $label): TextEntry
    {
        return TextEntry::make($key)->label($label)->state(fn (): mixed => $this->mountedRunEvidence[$key] ?? null)->wrap()->placeholder('Not recorded');
    }

    private function planEntry(string $key, string $label): TextEntry
    {
        return TextEntry::make($key)->label($label)->state(fn (): mixed => data_get($this->mountedPlan, $key))->wrap()->placeholder('Not recorded');
    }

    private function planMoneyEntry(string $key, string $label): TextEntry
    {
        return TextEntry::make($key)->label($label)
            ->state(fn (): string => $this->formatUnits(data_get($this->mountedPlan, $key), $this->mountedPlan['currency'] ?? null));
    }

    /** @return list<array<string, mixed>> */
    private function planBills(): array
    {
        $bills = [];
        foreach ($this->mountedPlan['bills'] ?? [] as $bill) {
            if (! is_array($bill)) {
                continue;
            }
            $currency = $this->mountedPlan['currency'] ?? null;
            $bills[] = [
                ...$bill,
                'source_amount' => $this->formatUnits($bill['source_minor_units'] ?? null, $currency),
                'valuation_amount' => $this->formatUnits($bill['valuation_base_units'] ?? null, 'USDC'),
                'remaining_budget' => $this->formatUnits($bill['remaining_budget_minor_units'] ?? null, $currency),
                'remaining_cash' => $this->formatUnits($bill['remaining_cash_minor_units'] ?? null, $currency),
                'decision' => match ($bill['decision'] ?? null) {
                    'propose_human_review' => 'Proposed for human review — not authorized', 'hold' => 'Hold', default => 'Not recorded',
                },
            ];
        }

        return $bills;
    }

    private function actor(): User
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function formatUnits(mixed $units, mixed $currency): string
    {
        return Money::formatExact($units, $currency);
    }
}
