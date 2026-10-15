<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\CaptureCollectionBatch;
use App\Actions\ImportTuitionPaymentAsCollection;
use App\Actions\ReviewCollectionBatch;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\StudentBillPayment;
use App\Models\StudentTuitionPayment;
use App\Models\User;
use App\Services\InstallationInstitution;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

class Collections extends Page implements HasTable
{
    use InteractsWithTable;
    use RestrictsFileUploadsToSchemaComponents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Banknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Financial Operations';

    protected static ?string $navigationLabel = 'Collections';

    protected static ?string $title = 'Collections';

    protected static ?int $navigationSort = 2;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.pages.collections';

    #[Locked]
    public ?int $mountedBatchId = null;

    #[Locked]
    public string $mountedBatchDigest = '';

    /** @var array<string, mixed> */
    #[Locked]
    public array $mountedBatchEvidence = [];

    #[Locked]
    public string $captureKey = '';

    private const array PAYMENT_CHANNELS = [
        'cashier-fees' => 'Cash / cashier counter',
        'tuition.bank-deposits' => 'Bank deposit / transfer',
        'digital-wallet-fees' => 'Digital wallet / payment app',
    ];

    #[\Override]
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return Filament::getCurrentPanel()?->getId() === 'admin' ? 'Finance records' : 'Financial Operations';
    }

    #[\Override]
    public static function canAccess(): bool
    {
        return Auth::user() instanceof User
            && Gate::forUser(Auth::user())->allows('create', CollectionBatch::class);
    }

    public function table(Table $table): Table
    {
        Gate::forUser(Auth::user())->authorize('create', CollectionBatch::class);
        $query = $this->batchesQuery()->with('preparer');
        $query->addSelect(['review_decision' => CollectionBatchReview::query()
            ->select('decision')
            ->whereColumn('collection_batch_id', 'collection_batches.id')
            ->limit(1)]);

        return $table
            ->query($query)
            ->heading('Collection history')
            ->description('Recorded receipts and reports, with independent review status.')
            ->defaultSort('id', 'desc')
            ->striped()
            ->poll('10s')
            ->stackedOnMobile()
            ->searchPlaceholder('Find receipt or report number')
            ->columns([
                TextColumn::make('source_reference')->label('Receipt / report')->searchable()->wrap()->limit(32)
                    ->tooltip(fn (CollectionBatch $record): string => $record->source_reference)
                    ->description(fn (CollectionBatch $record): string => 'Collection #'.$record->id.' — '.$this->channelLabel($record->source_stream)),
                TextColumn::make('received_minor_units')->label('Amount received')->alignEnd()
                    ->formatStateUsing(fn (CollectionBatch $record): string => $this->batchMoney($record, 'received_minor_units')),
                TextColumn::make('restricted_minor_units')->label('Set aside')->alignEnd()
                    ->formatStateUsing(fn (CollectionBatch $record): string => $this->batchMoney($record, 'restricted_minor_units')),
                TextColumn::make('collected_until')->label('Period ends')->dateTime('M j, Y H:i', config('app.timezone'))->sortable()
                    ->description(fn (CollectionBatch $record): string => 'From '.$record->collected_from->copy()->timezone(config('app.timezone'))->format('M j, H:i').' ('.config('app.timezone').')'),
                TextColumn::make('preparer.name')->label('Recorded by')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('review_decision')->label('Review status')->badge()->placeholder('Awaiting review')
                    ->formatStateUsing(fn (string $state): string => $this->decisionLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        'approve_receipts' => 'success', 'reject' => 'danger', default => 'warning',
                    })
                    ->description(fn (CollectionBatch $record): string => $this->nextStep($record)),
            ])
            ->filters([
                Filter::make('awaiting_review')->label('Awaiting review')
                    ->query(function (Builder $query): void {
                        $query->whereNotIn('id', CollectionBatchReview::query()->select('collection_batch_id'));
                    }),
                SelectFilter::make('source_stream')->label('Payment channel')->options(fn (): array => $this->channelOptions()),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
                Action::make('reevaluatePolicy')->label('Re-evaluate policy')->icon(Heroicon::Sparkles)->color('gray')
                    ->visible(fn (): bool => Gate::allows('create', InstitutionPolicyEvaluation::class))
                    ->url(fn (CollectionBatch $record): string => route('finance.policy-evaluations.index', ['source_type' => 'collection', 'source_id' => $record->id])),
                Action::make('viewCollectionBatch')->label('Details')->icon(Heroicon::Eye)->color('gray')
                    ->visible(fn (CollectionBatch $record): bool => Gate::forUser(Auth::user())->allows('view', $record))
                    ->modalHeading('Collection details')->modalWidth('3xl')->slideOver()
                    ->mountUsing(function (CollectionBatch $record, Schema $schema): void {
                        $this->mountBatchEvidence($record);
                        $schema->fill();
                    })
                    ->schema(fn (): array => $this->batchEvidenceSchema())
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('reviewCollectionBatch')->label('Review')->icon(Heroicon::ClipboardDocumentCheck)
                    ->visible(fn (CollectionBatch $record): bool => $record->getAttribute('review_decision') === null
                        && Gate::forUser(Auth::user())->allows('review', $record))
                    ->modalHeading('Review collection')->modalWidth('3xl')->slideOver()
                    ->modalDescription('Compare the receipt/report with received funds and any restrictions. Only a different authorized staff member can review.')
                    ->modalSubmitActionLabel('Save review')
                    ->mountUsing(function (CollectionBatch $record, Schema $schema): void {
                        Gate::forUser(Auth::user())->authorize('review', $record);
                        $this->mountBatchEvidence($record);
                        $isArcTx = str_starts_with((string) $record->source_reference, '0x');
                        $schema->fill([
                            'decision' => 'approve_receipts',
                            'verification_reference' => $isArcTx
                                ? 'Verified Arc Testnet transaction on block explorer'
                                : 'Verified signed cashier closing report',
                            'reason' => 'Receipt confirmed against college treasury wallet; approved for budget allocation.',
                            'attestation' => true,
                        ]);
                    })
                    ->schema(fn (): array => [
                        ...$this->batchEvidenceSchema(),
                        Section::make('Your review')->schema([
                            Select::make('decision')->label('Decision')->options([
                                'approve_receipts' => 'Approve receipt record',
                                'reject' => 'Reject receipt record',
                                'hold' => 'Needs clarification',
                            ])->required()->in(['approve_receipts', 'reject', 'hold']),
                            TextInput::make('verification_reference')->label('What did you check?')->required()->maxLength(255)
                                ->placeholder('Bank credit on Oct 10 / signed cashier closing report')
                                ->helperText('Name the bank entry, report, or register you checked independently.'),
                            Textarea::make('reason')->label('Review notes')->required()->maxLength(1000)->rows(3)->columnSpanFull()
                                ->placeholder('Amount and date match. No restricted funds. / Explain the difference.')
                                ->helperText('Review is final. Held or rejected records cannot be approved later on this screen.'),
                            Checkbox::make('attestation')
                                ->label('I independently checked this exact source evidence, received funds and restrictions. This is not payment authorization.')
                                ->accepted()->required()->columnSpanFull(),
                        ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
                    ])
                    ->action(function (CollectionBatch $record, array $data, Schema $schema, ReviewCollectionBatch $review): void {
                        Gate::forUser(Auth::user())->authorize('review', $record);
                        try {
                            if ($this->mountedBatchId !== $record->id || $this->mountedBatchDigest === '') {
                                throw ValidationException::withMessages(['attestation' => 'Reopen the review and inspect the exact batch evidence.']);
                            }
                            $review->handle($this->actor(), $record, $this->mountedBatchDigest, $data['decision'], $data['verification_reference'], $data['reason']);
                        } catch (ValidationException $exception) {
                            $this->reportActionValidation($exception, $schema);
                        }
                        Notification::make()->title('Review saved')->body('Receipt review only. No accounts changed and no payment authorized.')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Record your first collection')
            ->emptyStateDescription('Have an official receipt or completed collection report ready. Record its totals here; another staff member checks it next.');
    }

    /** @return list<Action> */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('captureCollectionBatch')->label('Record collection')->icon(Heroicon::Plus)
                ->visible(fn (): bool => Gate::forUser(Auth::user())->allows('create', CollectionBatch::class))
                ->modalHeading('Record collection')->modalWidth('3xl')->slideOver()
                ->modalDescription('Use an official receipt or a completed report. No technical IDs or document codes needed.')
                ->modalSubmitActionLabel('Save for review')
                ->mountUsing(function (Schema $schema): void {
                    Gate::forUser(Auth::user())->authorize('create', CollectionBatch::class);
                    $this->captureKey = (string) Str::uuid();
                    $currency = app(InstallationInstitution::class)->require()->currency;
                    $schema->fill(['currency' => CurrencyCode::tryFrom($currency)?->value, 'restricted_amount' => '0',
                        'cash_evidence_source' => 'uploaded_document']);
                })
                ->steps(fn (): array => $this->captureSteps())
                ->action(function (array $data, Schema $schema, CaptureCollectionBatch $capture): void {
                    Gate::forUser(Auth::user())->authorize('create', CollectionBatch::class);
                    try {
                        if (! ($data['source_document'] ?? null) instanceof TemporaryUploadedFile || ! Str::isUuid($this->captureKey)) {
                            throw ValidationException::withMessages(['source_document' => 'Upload a receipt or report in this form. Reopen the form if its upload expired.']);
                        }
                        $data['capture_key'] = $this->captureKey;
                        if (($data['cash_evidence_source'] ?? null) === 'uploaded_document') {
                            $data['cash_evidence_reference'] = 'Uploaded receipt/report: '.Str::lower(trim((string) ($data['source_reference'] ?? '')));
                        }
                        $batch = $capture->handle($this->actor(), $data, $data['source_document']);
                    } catch (ValidationException $exception) {
                        $this->reportActionValidation($exception, $schema);
                    }
                    Notification::make()->title('Collection recorded')->body('Collection #'.$batch->id.' awaits a different authorized reviewer. No accounts changed.')->success()->send();
                }),
            $this->reconcileTuitionPaymentAction(),
        ];
    }

    /**
     * Reconcile a verified student payment into a receipt.
     *
     * Only settled movements are offered, so the screen cannot present something
     * the action would refuse. The wording says "reconcile" rather than
     * "record" on purpose: the amount arrived when the transfer settled, and
     * what this does is bring it into the reviewed ledger — not count it twice.
     */
    private function reconcileTuitionPaymentAction(): Action
    {
        return Action::make('reconcileTuitionPayment')
            ->label('Reconcile student payment')
            ->icon(Heroicon::ArrowsRightLeft)
            ->color('gray')
            ->visible(fn (): bool => Gate::forUser(Auth::user())->allows('create', CollectionBatch::class))
            ->modalHeading('Reconcile a student payment')
            ->modalWidth('3xl')
            ->modalDescription('Student payments that already settled into this treasury. Reconciling records them for review; it does not receive new money.')
            ->modalSubmitActionLabel('Reconcile for review')
            ->form([
                Select::make('payment_kind')->label('Payment source')->options(['tuition' => 'Tuition', 'bill' => 'Other student bills'])
                    ->default('tuition')->required()->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('payment_id', null);
                    }),
                Select::make('payment_id')
                    ->label('Verified student payment')
                    ->options(fn (Get $get): array => $this->reconcilablePaymentOptions((string) ($get('payment_kind') ?? 'tuition')))
                    ->required()
                    ->live(),
                TextEntry::make('movement_detail')
                    ->label('What this records')
                    ->state(fn (Get $get): string => $this->paymentReconciliationDetail($get('payment_id'), (string) ($get('payment_kind') ?? 'tuition')))
                    ->wrap(),
            ])
            ->action(function (array $data, Schema $schema, ImportTuitionPaymentAsCollection $import): void {
                Gate::forUser(Auth::user())->authorize('create', CollectionBatch::class);

                $payment = $this->reconciliationPayment($data['payment_id'] ?? null, $data['payment_kind'] ?? 'tuition');

                if ($payment === null) {
                    Notification::make()->title('Nothing to reconcile')->body('That payment is no longer available to reconcile.')->danger()->send();

                    return;
                }

                try {
                    $batch = $import->handle($this->actor(), $payment, (string) Str::uuid());
                } catch (ValidationException $exception) {
                    $this->reportActionValidation($exception, $schema);
                }

                Notification::make()->title('Payment reconciled')
                    ->body('Collection #'.$batch->id.' awaits a different authorized reviewer. The money was already in the treasury; nothing new arrived and no payment was authorized.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Settled movements awaiting reconciliation, in the payer's terms.
     *
     * @return array<string, string>
     */
    private function reconcilablePaymentOptions(string $kind = 'tuition'): array
    {
        $treasury = (string) config('lepton.arc.treasury');

        $import = app(ImportTuitionPaymentAsCollection::class);
        $query = $kind === 'bill' ? $import->eligibleBillPayments($treasury) : $import->eligiblePayments($treasury);

        return $query
            ->limit(50)
            ->get()
            ->filter(fn (StudentTuitionPayment|StudentBillPayment $payment): bool => ! $payment instanceof StudentBillPayment || $payment->hasValidSettlement())
            ->mapWithKeys(fn (StudentTuitionPayment|StudentBillPayment $payment): array => [
                (string) $payment->getKey() => sprintf(
                    '%s — student %d, %s',
                    $payment->amount(),
                    $payment->student_id,
                    $payment->executed_at->utc()->format('j M Y H:i').' UTC',
                ),
            ])
            ->all();
    }

    /**
     * What the reviewer is about to approve, in exact units.
     *
     * The held-back overpayment is shown separately rather than folded into the
     * spendable figure, because the two are not the same promise: one is
     * receipts, the other is money owed back to a student.
     */
    private function paymentReconciliationDetail(mixed $paymentId, string $kind = 'tuition'): string
    {
        $payment = $this->reconciliationPayment($paymentId, $kind);

        if ($payment === null) {
            return 'Choose a payment to see its exact amounts.';
        }

        $held = $payment->overpayment_base_units > 0
            ? ' Held back as a student overpayment: '.$payment->overpayment().'.'
            : '';

        return 'Received by the treasury: '.$payment->amount()
            .($payment instanceof StudentBillPayment ? '. Applied to student bill: ' : '. Applied to tuition: ')
            .$payment->applied().'.'.$held
            .' Reconcile rather than recording it again.';
    }

    private function reconciliationPayment(mixed $paymentId, string $kind): StudentTuitionPayment|StudentBillPayment|null
    {
        if (! is_numeric($paymentId) || ! in_array($kind, ['tuition', 'bill'], true)) {
            return null;
        }

        $import = app(ImportTuitionPaymentAsCollection::class);
        $treasury = (string) config('lepton.arc.treasury');

        return ($kind === 'bill' ? $import->eligibleBillPayments($treasury) : $import->eligiblePayments($treasury))
            ->whereKey((int) $paymentId)->first();
    }

    /** @return list<Step> */
    private function captureSteps(): array
    {
        $rules = CaptureCollectionBatch::inputRules();

        return [
            Step::make('Receipt')->icon(Heroicon::DocumentText)->schema([
                Select::make('source_stream')->label('Payment channel')->options(fn (): array => $this->channelOptions())
                    ->placeholder('How did the college receive the money?')->required()->rules($rules['source_stream'])
                    ->in(fn (): array => array_keys($this->channelOptions()))
                    ->helperText('Use the channel of the register or account that already holds these receipts, so the same fees are not counted twice.'),
                TextInput::make('source_reference')->label('Receipt / report number')->required()->rules($rules['source_reference'])
                    ->placeholder('OR-001234 or CASHIER-2026-10-10')
                    ->helperText('Copy the number from the receipt, bank confirmation, or existing collection register. Do not invent a bank reference.'),
                FileUpload::make('source_document')->label('Receipt or collection report')->required()->storeFiles(false)
                    ->disk('local')->visibility('private')->previewable(false)->preventFilePathTampering()->fetchFileInformation(false)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'text/csv', 'text/plain', 'application/csv'])
                    ->maxSize(10240)->rules(CaptureCollectionBatch::documentRules())->columnSpanFull()
                    ->helperText('Upload a PDF, JPG, PNG, or CSV, up to 10 MB. Use a receipt scan, bank credit confirmation, or cashier closing report. Document fingerprint is automatic; file stays private.'),
                Select::make('cash_evidence_source')->label('Proof of received funds')->options([
                    'uploaded_document' => 'This receipt/report confirms money was received',
                    'separate_record' => 'Another bank entry or register confirms receipt',
                ])->required()->in(['uploaded_document', 'separate_record'])->live()->columnSpanFull()
                    ->helperText('An invoice, payment request, or pending transfer is not proof of received funds.'),
                TextInput::make('cash_evidence_reference')->label('Where can the reviewer confirm receipt?')
                    ->placeholder('Bank statement Oct 10, credit OR-001234 / cash register page 12')
                    ->visible(fn (Get $get): bool => $get('cash_evidence_source') === 'separate_record')->required()
                    ->rules($rules['cash_evidence_reference'])->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2]),
            Step::make('Amount & dates')->icon(Heroicon::Banknotes)->schema([
                Select::make('currency')->label('Receipt currency')->options(array_combine(CurrencyCode::values(), CurrencyCode::values()))
                    ->required()->rules($rules['currency'])->live()
                    ->helperText('Use the currency printed on the receipt. No conversion to USDC.'),
                TextInput::make('received_amount')->label('Total received')->inputMode('decimal')->required()->rules($rules['received_amount'])
                    ->regex(fn (Get $get): string => '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,'.(CurrencyCode::tryFrom($get('currency') ?? '')?->decimals() ?? 2).'})?$/D')
                    ->placeholder('1500.00')->live(onBlur: true)
                    ->helperText('Copy the total, without commas. Up to 2 decimals, or 6 for USDC.'),
                TextInput::make('restricted_amount')->label('Amount set aside')->inputMode('decimal')->required()->rules($rules['restricted_amount'])
                    ->regex(fn (Get $get): string => '/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,'.(CurrencyCode::tryFrom($get('currency') ?? '')?->decimals() ?? 2).'})?$/D')
                    ->live(onBlur: true)->columnSpanFull()
                    ->helperText('Leave 0 if none. Include scholarship, donor-restricted, or other funds that cannot cover ordinary bills.'),
                DateTimePicker::make('collected_from')->label('Period starts')->seconds(false)->native(false)
                    ->format('Y-m-d\TH:i:sP')->displayFormat('M j, Y H:i')->timezone(config('app.timezone'))
                    ->defaultFocusedDate(fn (): CarbonInterface => now())
                    ->required()->before('collected_until')->maxDate(fn (): CarbonInterface => now())
                    ->helperText('Start of the receipt/report coverage ('.config('app.timezone').').'),
                DateTimePicker::make('collected_until')->label('Period ends')->seconds(false)->native(false)
                    ->format('Y-m-d\TH:i:sP')->displayFormat('M j, Y H:i')->timezone(config('app.timezone'))
                    ->defaultFocusedDate(fn (): CarbonInterface => now())
                    ->required()->maxDate(fn (): CarbonInterface => now())
                    ->helperText('Use a completed period, not a future date ('.config('app.timezone').').'),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Step::make('Check & save')->icon(Heroicon::ClipboardDocumentCheck)->schema([
                TextEntry::make('capture_summary')->hiddenLabel()->state('Check the totals, then save for independent review. Saved records cannot be edited. No student balance, bank balance, or payment changes.')
                    ->columnSpanFull(),
                TextEntry::make('summary_reference')->label('Receipt / report')->state(fn (Get $get): mixed => $get('source_reference')),
                TextEntry::make('summary_channel')->label('Payment channel')->state(fn (Get $get): string => $this->channelLabel($get('source_stream') ?? '')),
                TextEntry::make('summary_received')->label('Total received')->state(fn (Get $get): string => $this->captureAmount($get, 'received_amount')),
                TextEntry::make('summary_restricted')->label('Set aside')->state(fn (Get $get): string => $this->captureAmount($get, 'restricted_amount')),
                TextEntry::make('summary_from')->label('Period starts')->state(fn (Get $get): mixed => $get('collected_from'))->dateTime('M j, Y H:i', config('app.timezone')),
                TextEntry::make('summary_until')->label('Period ends')->state(fn (Get $get): mixed => $get('collected_until'))->dateTime('M j, Y H:i', config('app.timezone')),
                Checkbox::make('source_stream_disjoint')->label('These receipts are not included in another collection record, including another payment channel.')
                    ->required()->accepted()->columnSpanFull(),
                Checkbox::make('received_not_forecast')->label('This money was received, and the amount set aside is correct. These are not expected or pending payments.')
                    ->required()->accepted()->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2]),
        ];
    }

    private function mountBatchEvidence(CollectionBatch $batch): void
    {
        Gate::forUser(Auth::user())->authorize('view', $batch);
        $batch->loadMissing('preparer');
        $review = CollectionBatchReview::query()->where('collection_batch_id', $batch->id)->first();
        $this->mountedBatchId = $batch->id;
        $this->mountedBatchDigest = $batch->snapshot_digest;
        $this->mountedBatchEvidence = [
            'id' => $batch->id, 'capture_key' => $batch->capture_key, 'prepared_by' => $batch->prepared_by,
            'preparer_name' => $batch->preparer->name ?? 'User #'.$batch->prepared_by,
            'source_stream' => $batch->source_stream, 'source_reference' => $batch->source_reference,
            'channel' => $this->channelLabel($batch->source_stream),
            'document_name' => $batch->snapshot['source_document']['name'] ?? 'No uploaded file — check the original source outside EduFlow',
            'has_document' => isset($batch->snapshot['source_document']),
            'source_document_digest' => $batch->source_document_digest,
            'received' => $this->batchMoney($batch, 'received_minor_units'), 'restricted' => $this->batchMoney($batch, 'restricted_minor_units'),
            'collected_from' => $batch->collected_from->copy()->utc()->toIso8601String(),
            'collected_until' => $batch->collected_until->copy()->utc()->toIso8601String(),
            'cash_evidence_reference' => $batch->snapshot['cash_evidence_reference'] ?? null,
            'integrity' => $batch->hasValidSnapshot() ? 'Saved record unchanged' : 'Saved record changed — review blocked',
            'review_decision' => $review === null ? 'Awaiting independent review' : $this->decisionLabel($review->decision),
            'verification_reference' => $review?->verification_reference, 'reason' => $review?->reason,
            'review_digest' => $review?->review_digest,
            'review_integrity' => $review === null ? 'No review recorded' : ($review->hasValidEvidence($batch) ? 'Intact review evidence' : 'Invalid review evidence'),
        ];
    }

    /** @return list<Component> */
    private function batchEvidenceSchema(): array
    {
        return [
            Section::make('Purpose & Review Status')
                ->description('In EduFlow, all incoming cash must be independently checked before it can be allocated to department budgets or used to pay bills (four-eyes governance).')
                ->schema([
                    TextEntry::make('human_purpose')
                        ->hiddenLabel()
                        ->state(function (): HtmlString {
                            $status = (string) ($this->mountedBatchEvidence['review_decision'] ?? '');
                            $isApproved = str_contains($status, 'approved');
                            $isAwaiting = str_contains($status, 'Awaiting');

                            $badge = $isApproved
                                ? '<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-400 dark:border-emerald-800/40">✓ Verified & Approved — Eligible for Budget Planning</span>'
                                : ($isAwaiting
                                    ? '<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:border-amber-800/40">⏳ Awaiting Independent Staff Review — Not Spendable Yet</span>'
                                    : '<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200 dark:bg-rose-950/40 dark:text-rose-400 dark:border-rose-800/40">⚠️ Held or Needs Clarification</span>');

                            $detail = $isApproved
                                ? 'This receipt has been independently checked against proof of funds. The cash is confirmed in the treasury and can be planned in the Finance Supervisor.'
                                : ($isAwaiting
                                    ? 'This receipt has been recorded by staff, but policy forbids using it until another authorized staff member confirms the proof of funds.'
                                    : 'This receipt was held or rejected during independent review. It will not be offered to budget planning.');

                            return new HtmlString('<div class="flex flex-col gap-2"><div>'.$badge.'</div><p class="text-xs text-gray-600 dark:text-gray-400 leading-relaxed">'.$detail.'</p></div>');
                        })->columnSpanFull(),
                ])->columnSpanFull(),
            Section::make('Receipt record')->description('Compare this saved record with the original receipt/report. Recording does not confirm a bank balance.')
                ->headerActions([
                    Action::make('allocateInSupervisor')->label('Allocate in Supervisor')->icon(Heroicon::ArrowRight)->color('success')
                        ->visible(fn (): bool => str_contains((string) ($this->mountedBatchEvidence['review_decision'] ?? ''), 'approved'))
                        ->url(fn (): string => route('filament.finance.pages.finance-supervisor')),
                    Action::make('evaluateWithPolicy')->label('Evaluate with Policy AI')->icon(Heroicon::Sparkles)->color('primary')
                        ->visible(fn (): bool => Gate::allows('create', InstitutionPolicyEvaluation::class))
                        ->url(fn (): string => route('finance.policy-evaluations.index', ['source_type' => 'collection', 'source_id' => $this->mountedBatchId])),
                    Action::make('downloadCollectionDocument')->label('Download receipt / report')->icon(Heroicon::ArrowDownTray)->color('gray')
                        ->visible(fn (): bool => ($this->mountedBatchEvidence['has_document'] ?? false) === true)
                        ->url(fn (): string => route('finance.collection-batches.document', ['collectionBatch' => $this->mountedBatchId])),
                ])
                ->schema([
                    TextEntry::make('source_reference')->label('Receipt reference / proof')
                        ->state(function (): HtmlString {
                            $ref = (string) ($this->mountedBatchEvidence['source_reference'] ?? '');
                            if (str_starts_with($ref, '0x') && strlen($ref) >= 42) {
                                $short = substr($ref, 0, 10).'…'.substr($ref, -8);
                                $url = 'https://testnet.arcscan.app/tx/'.$ref;

                                return new HtmlString('<div class="flex flex-col gap-1"><a href="'.$url.'" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 font-mono text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400">'.$short.' <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg></a><span class="font-mono text-[11px] text-gray-500 break-all select-all">'.e($ref).'</span></div>');
                            }

                            return new HtmlString('<span class="font-mono text-xs">'.e($ref).'</span>');
                        }),
                    $this->evidenceEntry('channel', 'Payment channel'),
                    $this->evidenceEntry('received', 'Total received'), $this->evidenceEntry('restricted', 'Amount set aside'),
                    $this->evidenceEntry('collected_from', 'Period starts')->dateTime('M j, Y H:i', config('app.timezone')),
                    $this->evidenceEntry('collected_until', 'Period ends')->dateTime('M j, Y H:i', config('app.timezone')),
                    $this->evidenceEntry('preparer_name', 'Recorded by'), $this->evidenceEntry('integrity', 'Record check'),
                    $this->evidenceEntry('document_name', 'Receipt / report file')->columnSpanFull(),
                    $this->evidenceEntry('cash_evidence_reference', 'Proof of received funds')->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Recorded review')->schema([
                $this->evidenceEntry('review_decision', 'Decision'), $this->evidenceEntry('review_integrity', 'Review integrity'),
                $this->evidenceEntry('verification_reference', 'What the reviewer checked')->columnSpanFull(),
                $this->evidenceEntry('reason', 'Review notes')->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Cryptographic audit trail (SHA-256)')->collapsible()->collapsed()->schema([
                $this->evidenceEntry('id', 'Collection ID'), $this->evidenceEntry('prepared_by', 'Recorder ID'),
                $this->evidenceEntry('source_stream', 'Source identifier')->columnSpanFull(),
                TextEntry::make('expected_digest')->label('Record fingerprint')
                    ->state(fn (): string => $this->mountedBatchDigest)->wrap()->copyable()->columnSpanFull(),
                $this->evidenceEntry('source_document_digest', 'Document fingerprint (SHA-256)')->copyable()->columnSpanFull(),
                $this->evidenceEntry('capture_key', 'Capture key')->columnSpanFull(),
                $this->evidenceEntry('review_digest', 'Review fingerprint')->copyable()->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
        ];
    }

    private function evidenceEntry(string $key, string $label): TextEntry
    {
        return TextEntry::make($key)->label($label)->state(fn (): mixed => $this->mountedBatchEvidence[$key] ?? null)->wrap()->placeholder('Not recorded');
    }

    private function batchMoney(CollectionBatch $batch, string $field): string
    {
        return new Money($batch->getAttribute($field), CurrencyCode::from($batch->currency))->format().' '.$batch->currency;
    }

    private function decisionLabel(string $decision): string
    {
        return match ($decision) {
            'approve_receipts' => 'Receipt record approved', 'reject' => 'Rejected', 'hold' => 'Needs clarification', default => $decision,
        };
    }

    /** @return array{awaiting: int, approved: int, held_or_rejected: int} */
    public function collectionOverview(): array
    {
        Gate::forUser(Auth::user())->authorize('create', CollectionBatch::class);

        return ['awaiting' => $this->batchesQuery()->whereDoesntHave('reviews')->count(),
            'approved' => $this->batchesQuery()->whereHas('reviews', fn (Builder $query): Builder => $query->where('decision', 'approve_receipts'))->count(),
            'held_or_rejected' => $this->batchesQuery()->whereHas('reviews', function (Builder $query): void {
                $query->whereIn('decision', ['hold', 'reject']);
            })->count()];
    }

    /** @return Builder<CollectionBatch> */
    private function batchesQuery(): Builder
    {
        return CollectionBatch::query()->where('organization_id', app(InstallationInstitution::class)->current()?->id);
    }

    /** @return array<string, string> */
    private function channelOptions(): array
    {
        $options = self::PAYMENT_CHANNELS;
        foreach ($this->batchesQuery()->distinct()->pluck('source_stream') as $stream) {
            $options[$stream] ??= $this->channelLabel($stream);
        }

        return $options;
    }

    private function channelLabel(string $stream): string
    {
        return self::PAYMENT_CHANNELS[$stream] ?? 'Existing register: '.str_replace(['.', '_', '-'], ' ', $stream);
    }

    private function nextStep(CollectionBatch $batch): string
    {
        return match ($batch->getAttribute('review_decision')) {
            'approve_receipts' => 'Recorded approval, not bank or payment verification',
            'reject', 'hold' => 'Ask finance staff; this decision is final',
            default => Gate::forUser(Auth::user())->allows('review', $batch)
                ? 'Open details, check proof, then review' : 'Another authorized staff member must review',
        };
    }

    private function captureAmount(Get $get, string $field): string
    {
        $currency = CurrencyCode::tryFrom($get('currency') ?? '');
        if ($currency === null || ! is_string($get($field))) {
            return 'Check amount and currency';
        }

        try {
            return Money::fromDecimal($get($field), $currency)->format().' '.$currency->value;
        } catch (\InvalidArgumentException|\OverflowException) {
            return 'Check amount and currency';
        }
    }

    private function actor(): User
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function reportActionValidation(ValidationException $exception, Schema $schema): never
    {
        $errors = [];
        foreach ($exception->errors() as $field => $messages) {
            $errors[$schema->getStatePath().'.'.($field === 'expected_digest' ? 'attestation' : $field)] = $messages;
        }
        Notification::make()->title('Collection not saved')->body(collect($exception->errors())->flatten()->first())->danger()->send();

        throw ValidationException::withMessages($errors);
    }
}
