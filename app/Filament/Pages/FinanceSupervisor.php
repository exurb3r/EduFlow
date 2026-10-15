<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\ApproveReceiptBudgetRoute;
use App\Actions\CaptureBudgetSnapshot;
use App\Actions\PrepareReceiptBudgetRoute;
use App\Actions\ProposeReceiptAllocation;
use App\Actions\ReviewFinancePlan;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Filament\Resources\PaymentSubmissions\PaymentSubmissionResource;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\FinancePlanReview;
use App\Models\FinanceWorkflowRun;
use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\InvoiceVersion;
use App\Models\PaymentIntent;
use App\Models\ReceiptBudgetRoute;
use App\Models\ReceiptPlanTrigger;
use App\Models\User;
use App\Services\AcceptedPlanPayments;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyWorkspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
use Illuminate\Support\Carbon;
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

    /** @var list<array<string, mixed>> */
    #[Locked]
    public array $mountedPaymentProgress = [];

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
            ->description('Refreshes every 10 seconds. Review exact proposals and prepare eligible bills for independent payment review; no transfer is submitted here.')
            ->defaultSort('id', 'desc')->striped()->poll('10s')
            ->columns([
                TextColumn::make('id')->label('Run')->prefix('#')->fontFamily('mono')->weight('bold')->sortable(),
                TextColumn::make('kind')->label('Workflow')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'budget_plan' => 'primary',
                        'collection_review' => 'info',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'budget_plan' => 'heroicon-m-scale',
                        'collection_review' => 'heroicon-m-inbox-stack',
                        default => 'heroicon-m-document',
                    })
                    ->formatStateUsing(fn (string $state): string => self::KINDS[$state] ?? $state),
                TextColumn::make('state')->badge()
                    ->formatStateUsing(fn (string $state): string => self::STATES[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success', 'waiting_for_review', 'blocked', 'paused' => 'warning',
                        'failed' => 'danger', 'running' => 'info', default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'completed' => 'heroicon-m-check-circle',
                        'waiting_for_review' => 'heroicon-m-clock',
                        'blocked', 'failed' => 'heroicon-m-exclamation-triangle',
                        'running' => 'heroicon-m-arrow-path',
                        default => 'heroicon-m-pause-circle',
                    }),
                TextColumn::make('trigger_key')->label('Trigger')->searchable()->fontFamily('mono')->limit(32)
                    ->copyable()
                    ->tooltip(fn (FinanceWorkflowRun $record): string => $record->trigger_key),
                TextColumn::make('budget_snapshot_id')->label('Budget snapshot')->prefix('#')->fontFamily('mono')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('collection_batch_id')->label('Collection batch')->prefix('#')->fontFamily('mono')->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attempts')->alignEnd()->badge()->color('gray'),
                TextColumn::make('heartbeat_at')->label('Heartbeat (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->placeholder('Not started')->sortable(),
                TextColumn::make('next_attempt_at')->label('Next attempt (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->placeholder('Not scheduled')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Created (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_error')->label('Last error')->limit(65)->wrap()->placeholder('None')
                    ->color(fn (?string $state): string => filled($state) ? 'danger' : 'gray')
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
                Action::make('preparePlanPayment')->label('Prepare payment review')->icon(Heroicon::ClipboardDocumentCheck)
                    ->visible(fn (FinanceWorkflowRun $record): bool => Gate::forUser(Auth::user())->allows('view', $record)
                        && FinancePlanReview::query()->where('finance_workflow_run_id', $record->id)->where('decision', 'accept_plan')->exists())
                    ->modalHeading('Prepare accepted bill for payment review')->modalWidth('5xl')
                    ->modalDescription('Uses this exact accepted plan and an independently reviewed funding window. Holds bill and fee capacity; does not approve or send a payment.')
                    ->mountUsing(function (FinanceWorkflowRun $record, Schema $schema): void {
                        $this->mountRunEvidence($record);
                        $schema->fill();
                    })
                    ->schema(fn (): array => [
                        Select::make('invoice_version_id')->label('Eligible proposed bill')->required()->options(fn (): array => collect($this->mountedPlan['bills'] ?? [])
                            ->filter(fn (array $bill): bool => $bill['decision'] === 'propose_human_review')
                            ->mapWithKeys(fn (array $bill): array => [$bill['invoice_version_id'] => 'Invoice #'.$bill['invoice_id'].' · '.$this->formatUnits($bill['valuation_base_units'], 'USDC')])->all()),
                        Select::make('funding_approval_digest')->label('Reviewed funding window')->required()->options(fn (): array => FundingWindowApproval::query()
                            ->where('organization_id', app(InstallationInstitution::class)->current()?->id)->whereNull('superseded_at')
                            ->whereIn('funding_window_id', FundingWindow::query()->select('id')->where('budget_snapshot_id', $this->mountedPlan['snapshot_id'] ?? null))
                            ->get()->mapWithKeys(fn (FundingWindowApproval $approval): array => [$approval->approval_digest => 'Funding approval #'.$approval->id])->all()),
                        TextInput::make('max_fee')->label('Maximum fee (USDC)')->inputMode('decimal')->required()
                            ->helperText('Exact six-decimal amount. Must match an existing draft; policy and funding checks still apply.'),
                    ])
                    ->action(function (FinanceWorkflowRun $record, array $data, Schema $schema, AcceptedPlanPayments $payments): void {
                        abort_unless($this->mountedRunId === $record->id && $this->mountedResultDigest !== '', 403);
                        $approval = FundingWindowApproval::query()->where('organization_id', $record->organization_id)
                            ->where('approval_digest', $data['funding_approval_digest'])->firstOrFail();
                        try {
                            $fee = Money::fromDecimal($data['max_fee'], CurrencyCode::USDC);
                        } catch (\InvalidArgumentException|\OverflowException) {
                            throw ValidationException::withMessages([$schema->getStatePath().'.max_fee' => 'Exact non-negative USDC amount with at most six decimals required.']);
                        }
                        try {
                            $hold = $payments->prepareBill($this->actor(), $record, (int) $data['invoice_version_id'], $approval,
                                $this->mountedResultDigest, $data['funding_approval_digest'], $fee);
                        } catch (ValidationException $exception) {
                            Notification::make()->title('Payment preparation refused')->body(collect($exception->errors())->flatten()->first())->danger()->send();

                            throw ValidationException::withMessages([$schema->getStatePath().'.invoice_version_id' => collect($exception->errors())->flatten()->all()]);
                        }
                        Notification::make()->title('Bill prepared for independent payment review')
                            ->body('Reservation #'.$hold->id.' holds capacity. Open Payment approvals for exact MFA-backed authorization; nothing was sent.')
                            ->success()->send();
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
            Action::make('proposeAllocation')
                ->label('Propose receipt allocation')
                ->icon(Heroicon::Scale)
                ->color('primary')
                ->button()
                ->visible(fn (): bool => Gate::forUser(Auth::user())->allows('create', BudgetSnapshot::class))
                ->modalHeading('Propose how received cash is allocated')
                ->modalWidth('4xl')
                ->modalSubmitActionLabel('Build proposal')
                ->modalDescription('The agent classifies each approved invoice and active budget line against the institution\'s published policy and ranks them in the order staff arranged. It proposes amounts only; it reserves nothing, approves nothing and pays nothing.')
                ->schema([
                    Select::make('snapshot_digest')->label('Realised receipt context')->required()->options(fn (): array => BudgetSnapshot::query()
                        ->where('organization_id', app(InstallationInstitution::class)->current()?->id)->orderByDesc('id')->limit(100)->get()
                        ->filter(fn (BudgetSnapshot $snapshot): bool => $snapshot->hasValidSnapshot() && $snapshot->budget->status === 'active')
                        ->mapWithKeys(fn (BudgetSnapshot $snapshot): array => [$snapshot->snapshot_digest => 'Snapshot #'.$snapshot->id.' · '
                            .$snapshot->snapshot['department']['name'].' · receipts '.Money::formatExact($snapshot->snapshot['amounts']['realized_receipts'], $snapshot->currency).' '.$snapshot->currency])->all()),
                    Placeholder::make('policy_note')->label('Policy in force')->content(function (): string {
                        $policy = app(InstitutionPolicyWorkspace::class)->active();

                        return $policy === null
                            ? 'No published policy with active controls. Publish one before proposing.'
                            : 'Revision #'.$policy->institution_policy_version_id.' · '.$policy->version->title.' · approved by #'.$policy->approved_by;
                    }),
                ])
                ->action(function (array $data, Schema $schema, ProposeReceiptAllocation $propose): void {
                    Gate::forUser(Auth::user())->authorize('create', BudgetSnapshot::class);
                    /** @var BudgetSnapshot $snapshot */
                    $snapshot = BudgetSnapshot::query()->where('organization_id', app(InstallationInstitution::class)->require()->id)
                        ->where('snapshot_digest', $data['snapshot_digest'])->firstOrFail();
                    try {
                        $plan = $propose->handle($this->actor(), $snapshot, (string) Str::uuid());
                    } catch (ValidationException $exception) {
                        $errors = [];
                        foreach ($exception->errors() as $field => $messages) {
                            $errors[$schema->getStatePath().'.'.$field] = $messages;
                        }
                        Notification::make()->danger()->title('Allocation proposal refused')
                            ->body(implode(' ', array_merge(...array_values($errors))))->persistent()->send();

                        return;
                    }
                    Notification::make()->success()->title('Allocation proposed, not approved')
                        ->body(Money::formatExact($plan->proposed_minor_units, $plan->currency).' '.$plan->currency.' proposed across '
                            .$plan->plan['proposals_awaiting_human_review'].' line(s). Nothing is reserved or paid; review each line before any payment.')
                        ->persistent()->send();
                }),

            Action::make('captureBudgetSnapshot')
                ->label('Capture planning snapshot')
                ->icon(Heroicon::PlusCircle)
                ->color('gray')
                ->visible(fn (): bool => Gate::forUser(Auth::user())->allows('create', BudgetSnapshot::class))
                ->modalHeading('Capture budget planning evidence')
                ->modalWidth('5xl')
                ->modalDescription('Immutable planning snapshot, not funding. Capture does not reserve funds, change accounts or authorize payment. New snapshots enter the default queue for background planning.')
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
                        ->body('Evidence only. No funding, payment authorization or accounts changed. Planning uses the default queue and requires a running worker.')
                        ->success()->send();
                }),

            $this->prepareReceiptRouteAction(),
            $this->approveReceiptRouteAction(),

            Action::make('paymentProgress')
                ->label('Payment progress')
                ->icon(Heroicon::QueueList)
                ->color('gray')
                ->visible(fn (): bool => PaymentSubmissionResource::canViewAny())
                ->url(fn (): string => PaymentSubmissionResource::getUrl(panel: 'finance')),
        ];
    }

    private function prepareReceiptRouteAction(): Action
    {
        return Action::make('prepareReceiptRoute')->label('Prepare receipt route')->icon(Heroicon::ArrowsRightLeft)->color('gray')
            ->visible(fn (): bool => Gate::forUser(Auth::user())->allows('create', ReceiptBudgetRoute::class))
            ->modalHeading('Prepare exact receipt routing')->modalWidth('5xl')
            ->modalSubmitActionLabel('Prepare route for review')
            ->modalDescription('Planning only. Selected staff context fixes budget, bill set, commitments and expiry. Incoming receipts still need independent review. No budget allocation, reservation or payment authority is created.')
            ->schema([
                Select::make('base_digest')->label('Staff planning context')->required()->options(fn (): array => BudgetSnapshot::query()
                    ->where('organization_id', app(InstallationInstitution::class)->current()?->id)->where('currency', 'USDC')->orderByDesc('id')->limit(100)->get()
                    ->filter(fn (BudgetSnapshot $snapshot): bool => ! isset($snapshot->snapshot['receipt_routing'])
                        && ($snapshot->snapshot['schema_version'] ?? null) === 3 && ($snapshot->snapshot['amounts']['opening_funds'] ?? null) === '0')
                    ->mapWithKeys(fn (BudgetSnapshot $snapshot): array => [$snapshot->snapshot_digest => 'Snapshot #'.$snapshot->id.' · '.$snapshot->snapshot['department']['name'].' · '.count($snapshot->snapshot['bills']).' bound bills'])->all()),
                Select::make('source_stream')->label('Exact receipt source')->required()->options([
                    CollectionBatch::TUITION_PORTAL_STREAM => 'Verified tuition receipts', CollectionBatch::BILL_PORTAL_STREAM => 'Verified other student bill receipts',
                ]),
                TextInput::make('receipts_from')->label('Receipt coverage starts (UTC)')->required()->default(fn (): string => now()->utc()->toIso8601String()),
                TextInput::make('valid_until')->label('Route valid until (UTC)')->required()->helperText('Cannot exceed original planning context expiry. Automation never renews evidence.'),
            ])
            ->action(function (array $data, Schema $schema, PrepareReceiptBudgetRoute $prepare): void {
                $base = BudgetSnapshot::query()->where('organization_id', app(InstallationInstitution::class)->require()->id)
                    ->where('snapshot_digest', $data['base_digest'])->firstOrFail();
                try {
                    $route = $prepare->handle($this->actor(), $base, $data['source_stream'], $data['receipts_from'], $data['valid_until'], (string) Str::uuid());
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([$schema->getStatePath().'.base_digest' => collect($exception->errors())->flatten()->all()]);
                }
                Notification::make()->title('Receipt route #'.$route->id.' prepared')->body('Different authorized reviewer must approve exact routing. No financial authority created.')->success()->send();
            });
    }

    private function approveReceiptRouteAction(): Action
    {
        return Action::make('approveReceiptRoute')->label('Review receipt route')->icon(Heroicon::ClipboardDocumentCheck)->color('gray')
            ->visible(fn (): bool => $this->actor()->hasAnyRole(['admin', 'super_admin']))
            ->modalHeading('Review exact receipt routing')->modalWidth('5xl')
            ->modalSubmitActionLabel('Approve planning route')
            ->modalDescription('Allows background capture of bounded non-executable successor proposals only. Receipt review, plan acceptance and exact MFA payment approval remain separate.')
            ->schema([
                Select::make('route_digest')->label('Prepared route')->required()->live()->options(fn (): array => ReceiptBudgetRoute::query()
                    ->where('organization_id', app(InstallationInstitution::class)->current()?->id)->whereDoesntHave('approval')->orderByDesc('id')->get()
                    ->filter(fn (ReceiptBudgetRoute $route): bool => Gate::forUser(Auth::user())->allows('approve', $route))
                    ->mapWithKeys(fn (ReceiptBudgetRoute $route): array => [$route->snapshot_digest => 'Route #'.$route->id.' · '.$route->source_stream.' · Snapshot #'.$route->budget_snapshot_id])->all()),
                TextEntry::make('route_scope')->label('Exact context')->state(function (Get $get): string {
                    $route = ReceiptBudgetRoute::query()->where('organization_id', app(InstallationInstitution::class)->current()?->id)
                        ->where('snapshot_digest', $get('route_digest'))->first();

                    return $route === null ? 'Choose a prepared route to inspect its scope.'
                        : 'Budget #'.$route->snapshot['budget_id'].' · Invoice versions '.implode(', ', array_column($route->snapshot['bills'], 'id'))
                            .' · '.$route->source_stream.' · '.$route->currency.' · '.$route->receipts_from->toIso8601String().' through '.$route->valid_until->toIso8601String()
                            .' · Bound amounts (base units): '.json_encode($route->budgetSnapshot->snapshot['amounts'], JSON_THROW_ON_ERROR)
                            .' · Source evidence: '.json_encode($route->budgetSnapshot->snapshot['evidence'], JSON_THROW_ON_ERROR)
                            .' · Digest '.$route->snapshot_digest;
                })->wrap(),
                Textarea::make('reason')->label('Independent review reason')->required()->maxLength(1000),
                Checkbox::make('attestation')->label('I reviewed exact source coverage, existing allocation, closed bills, commitments and expiry. This grants no payment authority.')->required()->accepted(),
            ])
            ->action(function (array $data, Schema $schema, ApproveReceiptBudgetRoute $approve): void {
                $route = ReceiptBudgetRoute::query()->where('organization_id', app(InstallationInstitution::class)->require()->id)
                    ->where('snapshot_digest', $data['route_digest'])->firstOrFail();
                try {
                    $approve->handle($this->actor(), $route, $data['route_digest'], $data['reason']);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages([$schema->getStatePath().'.attestation' => collect($exception->errors())->flatten()->all()]);
                }
                Notification::make()->title('Receipt routing reviewed')->body('Reviewed receipts can now trigger bounded successor planning on the default queue. No spending approved.')->success()->send();
            });
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
            Section::make('Receipt routing and successors')
                ->icon(Heroicon::ArrowsRightLeft)
                ->description('Approved mapping only. Routing never widens bill sets, refreshes expiry, accepts proposals or authorizes payment.')
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextEntry::make('receipt_routing_enabled')->label('Receipt-triggered planning')
                        ->state(fn (): string => config('eduflow.background_finance.receipt_routing_enabled', false) ? 'Enabled in configuration' : 'Disabled in configuration')
                        ->badge()->color(fn (): string => config('eduflow.background_finance.receipt_routing_enabled', false) ? 'success' : 'gray'),
                    RepeatableEntry::make('receipt_routes')->label('Prepared and approved contexts')->state(fn (): array => ReceiptBudgetRoute::query()
                        ->where('organization_id', app(InstallationInstitution::class)->current()?->id)->with('approval')->orderByDesc('id')->limit(20)->get()
                        ->map(fn (ReceiptBudgetRoute $route): array => ['id' => $route->id, 'source' => $route->source_stream,
                            'snapshot_id' => $route->budget_snapshot_id, 'status' => $route->approval === null ? 'Awaiting independent approval' : 'Approved for planning only',
                            'valid_until' => $route->valid_until->toIso8601String()])->all())
                        ->schema([
                            TextEntry::make('id')->label('Route ID')->prefix('#')->fontFamily('mono')->weight('bold'),
                            TextEntry::make('source')->label('Source Stream')->badge()->color('info'),
                            TextEntry::make('snapshot_id')->label('Staff Context Snapshot')->prefix('#')->fontFamily('mono'),
                            TextEntry::make('status')->label('Status')->badge()->color(fn (string $state): string => match ($state) {
                                'Approved for planning only' => 'success',
                                default => 'warning',
                            }),
                            TextEntry::make('valid_until')->label('Original Expiry (UTC)')->dateTime('Y-m-d H:i:s'),
                        ])->columns(['default' => 2, 'md' => 5]),
                    RepeatableEntry::make('receipt_triggers')->label('Receipt-triggered planning work')->state(fn (): array => ReceiptPlanTrigger::query()
                        ->where('organization_id', app(InstallationInstitution::class)->current()?->id)->orderByDesc('id')->limit(20)->get()->toArray())
                        ->schema([
                            TextEntry::make('collection_batch_review_id')->label('Receipt review ID')->prefix('#')->fontFamily('mono'),
                            TextEntry::make('state')->badge()->color(fn (string $state): string => match ($state) {
                                'completed' => 'success',
                                'blocked', 'failed' => 'danger',
                                'running' => 'info',
                                default => 'warning',
                            }),
                            TextEntry::make('predecessor_snapshot_id')->label('Predecessor snapshot')->prefix('#')->placeholder('Not captured')->fontFamily('mono'),
                            TextEntry::make('budget_snapshot_id')->label('Successor snapshot')->prefix('#')->placeholder('Not captured')->fontFamily('mono'),
                            TextEntry::make('last_error')->label('Blocked reason')->placeholder('None')->color('danger')->wrap(),
                        ])->columns(['default' => 2, 'md' => 5]),
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

    public ?string $activeStateFilter = null;

    public function filterByState(?string $state): void
    {
        Gate::forUser(Auth::user())->authorize('viewAny', FinanceWorkflowRun::class);
        $this->activeStateFilter = $state;

        if ($state === null) {
            $this->tableFilters = [];
        } else {
            $this->tableFilters = [
                'state' => ['value' => $state],
            ];
        }

        $this->updatedTableFilters();
    }

    /** @return array<string, mixed> */
    public function getSupervisorMetrics(): array
    {
        $orgId = app(InstallationInstitution::class)->current()?->id;

        $query = $this->runsQuery()->withoutEagerLoads();
        $waitingCount = (clone $query)->where('state', 'waiting_for_review')->count();
        $runningCount = (clone $query)->where('state', 'running')->count();
        $completedCount = (clone $query)->where('state', 'completed')->count();
        $failedCount = (clone $query)->whereIn('state', ['failed', 'blocked'])->count();
        $totalCount = (clone $query)->count();

        $acceptedCount = FinancePlanReview::query()
            ->where('organization_id', $orgId)
            ->where('decision', 'accept_plan')
            ->count();

        $activeRoutesCount = ReceiptBudgetRoute::query()
            ->where('organization_id', $orgId)
            ->whereHas('approval')
            ->count();

        $latestHeartbeat = (clone $query)
            ->whereNotNull('heartbeat_at')
            ->latest('heartbeat_at')
            ->value('heartbeat_at');

        $isEngineHealthy = (bool) config('eduflow.background_finance.enabled', false);

        return [
            'waiting' => $waitingCount,
            'running' => $runningCount,
            'completed' => $completedCount,
            'failed' => $failedCount,
            'total' => $totalCount,
            'accepted' => $acceptedCount,
            'activeRoutes' => $activeRoutesCount,
            'latestHeartbeat' => $latestHeartbeat ? Carbon::parse($latestHeartbeat)->diffForHumans() : 'No recent activity',
            'isEngineHealthy' => $isEngineHealthy,
            'queueConnection' => (string) config('queue.default', 'database'),
            'queueName' => (string) config('eduflow.background_finance.queue_connection', 'policy-assessments'),
        ];
    }

    public function showWaitingPlans(): void
    {
        Gate::forUser(Auth::user())->authorize('viewAny', FinanceWorkflowRun::class);
        $this->activeStateFilter = 'waiting_for_review';
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
        $this->mountedPaymentProgress = $run->kind === 'budget_plan' && $run->hasValidResult()
            ? app(AcceptedPlanPayments::class)->progress($this->actor(), $run) : [];
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
            Section::make('Run Summary & Status')
                ->icon(Heroicon::ShieldCheck)
                ->description('Workflow execution status and cryptographic integrity.')
                ->schema([
                    Grid::make(['default' => 2, 'sm' => 4])->schema([
                        TextEntry::make('run_id_display')
                            ->label('Run #')
                            ->state(fn (): string => '#'.($this->mountedRunEvidence['id'] ?? '—'))
                            ->fontFamily('mono')
                            ->weight('bold'),
                        TextEntry::make('kind')
                            ->label('Workflow Type')
                            ->badge()
                            ->color(fn (): string => ($this->mountedRunEvidence['kind'] ?? '') === self::KINDS['budget_plan'] ? 'primary' : 'info')
                            ->icon(fn (): string => ($this->mountedRunEvidence['kind'] ?? '') === self::KINDS['budget_plan'] ? 'heroicon-m-scale' : 'heroicon-m-inbox-stack')
                            ->state(fn (): string => $this->mountedRunEvidence['kind'] ?? '—'),
                        TextEntry::make('state')
                            ->label('Execution State')
                            ->badge()
                            ->color(fn (): string => match ($this->mountedRunEvidence['state'] ?? '') {
                                'Completed' => 'success',
                                'Waiting for review', 'Blocked', 'Paused' => 'warning',
                                'Failed' => 'danger',
                                'Running' => 'info',
                                default => 'gray',
                            })
                            ->icon(fn (): string => match ($this->mountedRunEvidence['state'] ?? '') {
                                'Completed' => 'heroicon-m-check-circle',
                                'Waiting for review' => 'heroicon-m-clock',
                                'Failed', 'Blocked' => 'heroicon-m-exclamation-triangle',
                                'Running' => 'heroicon-m-arrow-path',
                                default => 'heroicon-m-pause-circle',
                            })
                            ->state(fn (): string => $this->mountedRunEvidence['state'] ?? '—'),
                        TextEntry::make('attempts')
                            ->label('Attempts')
                            ->badge()
                            ->color('gray')
                            ->state(fn (): int => (int) ($this->mountedRunEvidence['attempts'] ?? 1)),
                    ]),

                    Grid::make(['default' => 1, 'sm' => 3])->schema([
                        TextEntry::make('heartbeat_at')
                            ->label('Heartbeat (UTC)')
                            ->state(fn (): string => $this->mountedRunEvidence['heartbeat_at'] ?? 'Not started')
                            ->icon(Heroicon::Clock),
                        TextEntry::make('created_at')
                            ->label('Created (UTC)')
                            ->state(fn (): string => $this->mountedRunEvidence['created_at'] ?? '—')
                            ->icon(Heroicon::Calendar),
                        TextEntry::make('integrity')
                            ->label('Result Integrity')
                            ->badge()
                            ->color(fn (): string => str_contains((string) ($this->mountedRunEvidence['integrity'] ?? ''), 'Intact') ? 'success' : 'danger')
                            ->icon(fn (): string => str_contains((string) ($this->mountedRunEvidence['integrity'] ?? ''), 'Intact') ? 'heroicon-m-check-badge' : 'heroicon-m-exclamation-triangle')
                            ->state(fn (): string => $this->mountedRunEvidence['integrity'] ?? 'No result recorded'),
                    ]),

                    TextEntry::make('last_error')
                        ->label('Last Error / Halt Reason')
                        ->state(fn (): ?string => $this->mountedRunEvidence['last_error'] ?? null)
                        ->color('danger')
                        ->icon(Heroicon::ExclamationCircle)
                        ->visible(fn (): bool => filled($this->mountedRunEvidence['last_error'] ?? null))
                        ->columnSpanFull(),

                    Section::make('Cryptographic Evidence & Technical Digests')
                        ->description('Auditor-verifiable SHA-256 hashes and trigger keys.')
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            TextEntry::make('trigger_key')->label('Trigger key')
                                ->state(fn (): string => $this->mountedRunEvidence['trigger_key'] ?? '—')
                                ->fontFamily('mono')->copyable()->columnSpanFull(),
                            TextEntry::make('source_digest')->label('Source digest')
                                ->state(fn (): string => $this->mountedRunEvidence['source_digest'] ?? '—')
                                ->fontFamily('mono')->copyable()->columnSpanFull(),
                            TextEntry::make('expected_digest')->label('Mounted result digest')
                                ->state(fn (): string => $this->mountedResultDigest)
                                ->fontFamily('mono')->copyable()->columnSpanFull(),
                            $this->runEntry('budget_snapshot_id', 'Budget snapshot ID')->fontFamily('mono'),
                            $this->runEntry('collection_batch_id', 'Collection batch ID')->fontFamily('mono'),
                            $this->runEntry('organization_id', 'Institution ID')->fontFamily('mono'),
                        ])->columns(3)->columnSpanFull(),
                ])->columnSpanFull(),

            Section::make('Exact Budget Proposal')
                ->icon(Heroicon::Banknotes)
                ->visible(fn (): bool => ($this->mountedRunEvidence['kind'] ?? null) === self::KINDS['budget_plan'] && $this->mountedPlan !== [])
                ->description('AI-evaluated line allocations against institutional policy. Recommendations only — not payment instructions.')
                ->schema([
                    Grid::make(['default' => 1, 'sm' => 3])->schema([
                        $this->planEntry('department.name', 'Department')->weight('bold')->icon(Heroicon::BuildingOffice),
                        $this->planEntry('currency', 'Budget Currency')->badge()->color('info'),
                        TextEntry::make('suggested_usdc_valuation')
                            ->label('Suggested USDC Valuation')
                            ->state(fn (): string => $this->formatUnits($this->mountedPlan['suggested_usdc_valuation_base_units'] ?? null, 'USDC'))
                            ->weight('bold')
                            ->color('primary')
                            ->icon(Heroicon::CurrencyDollar),
                    ]),

                    Grid::make(['default' => 2, 'sm' => 4])->schema([
                        $this->planMoneyEntry('headroom.budget_minor_units', 'Initial Budget Headroom'),
                        $this->planMoneyEntry('headroom.cash_minor_units', 'Initial Realized Cash'),
                        $this->planMoneyEntry('remaining_budget_minor_units', 'Remaining Budget'),
                        $this->planMoneyEntry('remaining_cash_minor_units', 'Remaining Cash'),
                    ]),

                    RepeatableEntry::make('planned_bills')
                        ->label('Closed Bill Set & Policy Rule Checks')
                        ->state(fn (): array => $this->planBills())
                        ->schema([
                            Grid::make(['default' => 2, 'sm' => 4])->schema([
                                TextEntry::make('invoice_id')->label('Invoice #')->prefix('#')->fontFamily('mono')->weight('bold'),
                                TextEntry::make('source_amount')->label('Billed Amount')->weight('bold'),
                                TextEntry::make('valuation_amount')->label('USDC Valuation')->weight('bold')->color('primary'),
                                TextEntry::make('decision')
                                    ->label('Planning Decision')
                                    ->badge()
                                    ->color(fn (string $state): string => str_contains($state, 'Proposed') ? 'warning' : 'danger'),
                            ]),
                            Grid::make(['default' => 2, 'sm' => 4])->schema([
                                IconEntry::make('checks.evidence_intact')->label('Evidence Intact')->boolean(),
                                IconEntry::make('checks.evidence_approved')->label('Evidence Approved')->boolean(),
                                IconEntry::make('checks.allocation_available')->label('Budget Available')->boolean(),
                                IconEntry::make('checks.realized_cash_available')->label('Cash Realized')->boolean(),
                            ]),
                            Grid::make(['default' => 1, 'sm' => 2])->schema([
                                TextEntry::make('remaining_budget')->label('Remaining Budget After Line'),
                                TextEntry::make('remaining_cash')->label('Remaining Cash After Line'),
                            ]),
                        ])->columns(1)->columnSpanFull(),
                ])->columnSpanFull(),

            Section::make('Bill payment progress')
                ->icon(Heroicon::QueueList)
                ->visible(fn (): bool => $this->mountedPaymentProgress !== [])
                ->description('Persisted vendor-payment work linked by exact invoice version. Reopen details for current progress; viewing never dispatches work.')
                ->schema([
                    RepeatableEntry::make('payment_progress')->hiddenLabel()->state(fn (): array => $this->mountedPaymentProgress)
                        ->schema([
                            Grid::make(['default' => 2, 'sm' => 3])->schema([
                                TextEntry::make('invoice_id')->label('Invoice #')->prefix('#')->fontFamily('mono')->weight('bold'),
                                TextEntry::make('state')->label('Payment State')->badge()->color(fn (string $state): string => match ($state) {
                                    'completed', 'settled' => 'success',
                                    'awaiting_payment_review' => 'warning',
                                    'failed' => 'danger',
                                    default => 'info',
                                }),
                                TextEntry::make('stage')->label('Pipeline Stage')->badge()->color('gray'),
                            ]),
                            Grid::make(['default' => 1, 'sm' => 2])->schema([
                                TextEntry::make('provider_handle')->label('Provider Transaction ID')->fontFamily('mono')->copyable()->placeholder('Not recorded'),
                                TextEntry::make('tx_hash')->label('Chain Hash — Not Proof Alone')->fontFamily('mono')->copyable()->placeholder('Not published'),
                            ]),
                            IconEntry::make('settled')->label('Verified Settlement Recorded')->boolean(),
                        ])->columns(1)->columnSpanFull(),
                ])->columnSpanFull(),

            Section::make('Authority Boundary')
                ->icon(Heroicon::InformationCircle)
                ->collapsed()
                ->description('Collection and plan reviews do not verify bank balances or Arc funding. Preparing payment review holds capacity only.')
                ->schema([
                    TextEntry::make('authority')->hiddenLabel()->state('Accepting a plan grants no payment authority. Prepare payment review may hold capacity against independently reviewed funding. Exact MFA-backed payment approval and background execution remain separate.'),
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
