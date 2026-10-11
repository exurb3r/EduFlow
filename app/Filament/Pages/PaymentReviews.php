<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\EnrollPaymentReviewer;
use App\Actions\ReviewVendorPayment;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\FundingWindow;
use App\Models\FundingWindowApproval;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\PaymentReviewerEnrollment;
use App\Models\User;
use App\Services\InstallationInstitution;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use SensitiveParameter;
use UnitEnum;

class PaymentReviews extends Page implements HasTable
{
    use InteractsWithTable;
    use RestrictsFileUploadsToSchemaComponents;

    private const array DECISIONS = [
        'approve_payment' => 'Reviewed — executor disabled',
        'reject_payment' => 'Rejected — reservation held',
        'hold_payment' => 'On hold — reservation held',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Automation';

    protected static ?string $navigationLabel = 'Payment Approvals';

    protected static ?string $title = 'Payment approvals';

    protected static ?int $navigationSort = 3;

    protected static bool $isDiscovered = false;

    protected string $view = 'filament.pages.payment-reviews';

    #[Locked]
    public ?int $mountedIntentId = null;

    #[Locked]
    public ?int $mountedReservationId = null;

    #[Locked]
    public string $mountedIntentDigest = '';

    #[Locked]
    public string $mountedReservationDigest = '';

    #[Locked]
    public string $mountedRequestKey = '';

    #[Locked]
    public string $mountedEnrollmentRequestKey = '';

    /** @var array<string, mixed> */
    #[Locked]
    public array $mountedPaymentEvidence = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $mountedReviewEvidence = [];

    #[\Override]
    public static function canAccess(): bool
    {
        return Auth::user() instanceof User
            && Gate::forUser(Auth::user())->allows('create', PaymentIntent::class);
    }

    #[\Override]
    public function getSubheading(): ?string
    {
        return 'Review reserved payments here, not in Payment Submissions. Open Review payment to approve exact payment authority, reject, or hold. '
            .'Approval requires an independent reviewer with directly assigned AuthorizePayment:PaymentIntent permission and enrolled MFA. '
            .'Agent advice is not payment authority. This review does not itself send money.';
    }

    public function table(Table $table): Table
    {
        Gate::forUser($this->actor())->authorize('create', PaymentIntent::class);

        return $table
            ->query($this->reservationsQuery())
            ->heading('Reserved institution payments')
            ->description('Refreshes every 10 seconds. Review exact held payment evidence; recorded authority cannot execute a transfer.')
            ->defaultSort('id', 'desc')->striped()->poll('10s')
            ->columns([
                TextColumn::make('id')->label('Reservation')->prefix('#')->sortable(),
                TextColumn::make('payment_intent_id')->label('Payment intent')->prefix('#'),
                TextColumn::make('invoice_id')->label('Bill')->prefix('#'),
                TextColumn::make('amount_base_units')->label('Reserved amount')->alignEnd()
                    ->formatStateUsing(fn (PaymentReservation $record): string => $this->formatUsdc($record->amount_base_units)),
                TextColumn::make('max_fee_base_units')->label('Fee ceiling')->alignEnd()
                    ->formatStateUsing(fn (PaymentReservation $record): string => $this->formatUsdc($record->max_fee_base_units)),
                TextColumn::make('intent.recipient_address')->label('Recipient')->limit(22)->wrap()
                    ->tooltip(fn (PaymentReservation $record): ?string => $record->intent?->recipient_address),
                TextColumn::make('intent.chain')->label('Chain')->badge()->color('gray'),
                TextColumn::make('review_decision')->label('Review')->badge()
                    ->state(fn (PaymentReservation $record): string => $record->authorization->decision ?? 'pending')
                    ->formatStateUsing(fn (string $state): string => $state === 'pending' ? 'Pending' : (self::DECISIONS[$state] ?? 'Unknown — executor disabled'))
                    ->color(fn (string $state): string => match ($state) {
                        'reject_payment' => 'danger', 'pending', 'hold_payment' => 'warning', default => 'gray',
                    }),
                TextColumn::make('evidence_mode')->label('Evidence mode')->badge()->color('gray')
                    ->state(fn (PaymentReservation $record): string => match ($record->snapshot['balance_observation']['is_fake'] ?? null) {
                        true => 'Simulation only', false => 'Network evidence', default => 'Unknown',
                    }),
                TextColumn::make('created_at')->label('Reserved (UTC)')->dateTime('Y-m-d H:i:s', 'UTC')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('viewPaymentEvidence')
                    ->label(fn (PaymentReservation $record): string => $this->hasReview($record) ? 'Evidence only' : 'Evidence')
                    ->icon(Heroicon::Eye)->color('gray')
                    ->visible(fn (PaymentReservation $record): bool => $record->intent instanceof PaymentIntent
                        && Gate::forUser($this->actor())->allows('view', $record->intent))
                    ->modalHeading('Exact payment and reservation evidence')->modalWidth('6xl')->slideOver()
                    ->mountUsing(function (PaymentReservation $record, Schema $schema): void {
                        $this->mountPaymentEvidence($this->currentReservation($record));
                        $schema->fill();
                    })
                    ->schema(fn (): array => $this->paymentEvidenceSchema())
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                Action::make('reviewReservedPayment')->label('Review payment')->icon(Heroicon::ClipboardDocumentCheck)
                    ->visible(fn (PaymentReservation $record): bool => $this->canReview($record))
                    ->modalHeading('Review exact reserved payment')->modalWidth('6xl')
                    ->modalDescription('Execution is disabled. This records independent payment authority only; no transfer or local payment state change. Rejecting or holding keeps the reservation held.')
                    ->modalSubmitActionLabel('Record payment review')
                    ->mountUsing(function (PaymentReservation $record, Schema $schema): void {
                        $reservation = $this->currentReservation($record);
                        $this->authorizeReview($reservation);
                        abort_if($this->hasReview($reservation), 403, 'Payment already reviewed. Evidence only; renewal requires a separate reviewed workflow.');
                        $this->mountPaymentEvidence($reservation);
                        $this->mountedRequestKey = (string) Str::uuid();
                        $schema->fill([
                            'valid_until' => $this->defaultApprovalExpiry(), 'mfa_method' => 'filament_app',
                            'password' => null, 'mfa_code' => null, 'attestation' => false,
                        ]);
                    })
                    ->schema(fn (): array => [...$this->paymentEvidenceSchema(), ...$this->reviewSchema()])
                    ->action(function (PaymentReservation $record, #[SensitiveParameter] array $data, Schema $schema, Action $action, ReviewVendorPayment $review): void {
                        try {
                            if ($this->mountedReservationId !== $record->id || $this->mountedIntentId === null
                                || $this->mountedIntentDigest === '' || $this->mountedReservationDigest === ''
                                || ! Str::isUuid($this->mountedRequestKey)) {
                                throw ValidationException::withMessages(['attestation' => 'Reopen the review and inspect the exact payment and reservation.']);
                            }
                            $reservation = $this->currentReservation($record);
                            $this->authorizeReview($reservation);
                            if ($reservation->payment_intent_id !== $this->mountedIntentId) {
                                throw ValidationException::withMessages(['attestation' => 'Payment identity changed. Reopen the review.']);
                            }
                            $review->handle($this->actor(), $reservation->intent, [
                                ...Arr::only($data, ['decision', 'reason', 'password', 'mfa_method', 'mfa_code']),
                                'request_key' => $this->mountedRequestKey,
                                'payment_reservation_id' => $this->mountedReservationId,
                                'intent_digest' => $this->mountedIntentDigest,
                                'reservation_digest' => $this->mountedReservationDigest,
                                'valid_until' => $data['decision'] === 'approve_payment' ? ($data['valid_until'] ?? null) : null,
                            ]);
                        } catch (ValidationException $exception) {
                            $errors = [];
                            foreach ($exception->errors() as $field => $messages) {
                                $target = in_array($field, ['decision', 'reason', 'valid_until', 'password', 'mfa_method', 'mfa_code', 'attestation'], true)
                                    ? $field : 'attestation';
                                $path = $schema->getStatePath().'.'.$target;
                                $errors[$path] = array_merge($errors[$path] ?? [], $messages);
                            }
                            Notification::make()->title('Payment review refused')
                                ->body('Check the highlighted fields and exact evidence. No transfer or local payment state change; the reservation remains held.')
                                ->danger()->send();

                            throw ValidationException::withMessages($errors);
                        } finally {
                            data_set($this, $schema->getStatePath().'.password', null);
                            data_set($this, $schema->getStatePath().'.mfa_code', null);
                            $action->resetData();
                            unset($data['password'], $data['mfa_code']);
                        }
                        Notification::make()->title('Payment review recorded')
                            ->body('Authority evidence recorded only. Execution remains disabled; no transfer or local payment state change. The reservation remains held, including rejection or hold.')
                            ->success()->send();
                    }),
            ])
            ->emptyStateHeading('No reserved payments')
            ->emptyStateDescription('Institution payment reservations appear here after the funding workflow. This page cannot reserve funds, release holds or execute payments.');
    }

    /** @return list<Action> */
    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('enrollPaymentReviewer')->label('Enroll payment reviewer')->icon(Heroicon::UserPlus)
                ->visible(fn (): bool => Gate::forUser($this->actor())->allows('create', PaymentReviewerEnrollment::class))
                ->modalHeading('Independently enroll payment reviewer')->modalWidth('5xl')
                ->modalDescription('Pin another eligible staff member’s existing authenticator after independent identity and factor verification. Pinning protects against account factor replacement. Enrollment grants no payment permission, approves no payment and cannot execute a transfer. Existing enrollment cannot be rotated here.')
                ->modalSubmitActionLabel('Record reviewer enrollment')
                ->mountUsing(function (Schema $schema): void {
                    Gate::forUser($this->actor())->authorize('create', PaymentReviewerEnrollment::class);
                    app(InstallationInstitution::class)->require();
                    $this->mountedEnrollmentRequestKey = (string) Str::uuid();
                    $schema->fill([
                        'reviewer_id' => null, 'reviewer_mfa_method' => 'filament_app', 'verification_reference' => null,
                        'password' => null, 'mfa_method' => 'filament_app', 'mfa_code' => null, 'attestation' => false,
                    ]);
                })
                ->schema(fn (): array => $this->enrollmentSchema())
                ->action(function (#[SensitiveParameter] array $data, Schema $schema, Action $action, EnrollPaymentReviewer $enroll): void {
                    try {
                        Gate::forUser($this->actor())->authorize('create', PaymentReviewerEnrollment::class);
                        if (! Str::isUuid($this->mountedEnrollmentRequestKey)) {
                            throw ValidationException::withMessages(['attestation' => 'Reopen enrollment and independently verify the exact reviewer and existing factor.']);
                        }
                        /** @var User|null $reviewer */
                        $reviewer = $this->eligibleReviewersQuery()->whereKey($data['reviewer_id'])->first();
                        if ($reviewer === null) {
                            throw ValidationException::withMessages(['reviewer_id' => 'Select another verified finance or admin staff member with directly assigned payment permission.']);
                        }
                        $enroll->handle($this->actor(), $reviewer, [
                            ...Arr::only($data, ['reviewer_mfa_method', 'verification_reference', 'password', 'mfa_method', 'mfa_code']),
                            'request_key' => $this->mountedEnrollmentRequestKey,
                        ]);
                    } catch (ValidationException $exception) {
                        $errors = [];
                        foreach ($exception->errors() as $field => $messages) {
                            $target = $field === 'reviewer' ? 'reviewer_id' : $field;
                            if (! in_array($target, ['reviewer_id', 'reviewer_mfa_method', 'verification_reference', 'password', 'mfa_method', 'mfa_code', 'attestation'], true)) {
                                $target = 'attestation';
                            }
                            $path = $schema->getStatePath().'.'.$target;
                            $errors[$path] = array_merge($errors[$path] ?? [], $messages);
                        }
                        Notification::make()->title('Reviewer enrollment refused')
                            ->body('Check the highlighted fields. Existing factor pins cannot be replaced here; independent recovery is required. No permission granted or payment approved.')
                            ->danger()->send();

                        throw ValidationException::withMessages($errors);
                    } finally {
                        data_set($this, $schema->getStatePath().'.password', null);
                        data_set($this, $schema->getStatePath().'.mfa_code', null);
                        $action->resetData();
                        unset($data['password'], $data['mfa_code']);
                    }
                    Notification::make()->title('Payment reviewer enrollment recorded')
                        ->body('Existing reviewer authenticator pinned against account factor replacement. No payment permission granted, payment approved or transfer executed. Factor recovery cannot be performed here.')
                        ->success()->send();
                }),
        ];
    }

    /** @return list<Component> */
    private function enrollmentSchema(): array
    {
        $rules = EnrollPaymentReviewer::inputRules();

        return [
            Section::make('Reviewer identity and existing factor')->schema([
                TextEntry::make('enrollment_request_key')->label('Server-generated enrollment request key (UUID)')
                    ->state(fn (): string => $this->mountedEnrollmentRequestKey)->wrap()->copyable()->columnSpanFull(),
                Select::make('reviewer_id')->label('Other eligible staff reviewer')->searchable()->required()->rules(['integer', 'min:1'])
                    ->options(function (): array {
                        /** @var Collection<int, User> $reviewers */
                        $reviewers = $this->eligibleReviewersQuery()->orderBy('name')->get(['id', 'name', 'email']);

                        return $reviewers->mapWithKeys(fn (User $reviewer): array => [$reviewer->id => '#'.$reviewer->id.' — '.$reviewer->name.' ('.$reviewer->email.')'])->all();
                    })
                    ->live()->afterStateUpdated(fn (Set $set): mixed => $set('attestation', false))
                    ->helperText('Verified finance/admin staff in this single-institution installation with directly assigned AuthorizePayment:PaymentIntent. No permissions are assigned here.')
                    ->columnSpanFull(),
                TextEntry::make('enrollment_reviewer_id')->label('Exact target reviewer ID')
                    ->state(fn (Get $get): ?string => filled($get('reviewer_id')) ? '#'.$get('reviewer_id') : null)
                    ->placeholder('Select a reviewer')->columnSpanFull(),
                Select::make('reviewer_mfa_method')->label('Reviewer’s existing authenticator to pin')->options([
                    'filament_app' => 'Filament authenticator app', 'fortify_totp' => 'Fortify TOTP',
                ])->required()->rules($rules['reviewer_mfa_method'])->live()
                    ->afterStateUpdated(fn (Set $set): mixed => $set('attestation', false))
                    ->helperText('Independently verify ownership of this already configured factor. Existing enrollment cannot be replaced or rotated here.')
                    ->columnSpanFull(),
                TextInput::make('verification_reference')->label('Independent identity and factor verification reference')
                    ->required()->rules($rules['verification_reference'])->maxLength(255)
                    ->helperText('Reference the independent verification record; never enter passwords, authenticator secrets or codes here.')
                    ->columnSpanFull(),
                Checkbox::make('attestation')
                    ->label('I independently verified this exact selected reviewer ID and ownership of the selected existing authenticator. I am not the reviewer and we do not share a factor. Enrollment pins that factor against account factor replacement; it grants no payment permission or payment, and cannot bypass independent factor recovery.')
                    ->required()->accepted()->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Your checker authentication')->description('Use your own current password and an unused TOTP code, not the reviewer’s credentials.')
                ->schema([
                    TextInput::make('password')->label('Your current password')->password()->autocomplete('current-password')
                        ->required()->rules($rules['password']),
                    Select::make('mfa_method')->label('Your authenticator method')->options([
                        'filament_app' => 'Filament authenticator app', 'fortify_totp' => 'Fortify TOTP',
                    ])->required()->rules($rules['mfa_method']),
                    TextInput::make('mfa_code')->label('Your six-digit authenticator code')->password()->inputMode('numeric')->autocomplete('one-time-code')
                        ->required()->rules($rules['mfa_code'])->minLength(6)->maxLength(6)
                        ->helperText('Include any leading zero. Email and recovery codes are not accepted.'),
                ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
        ];
    }

    /** @return Builder<User> */
    private function eligibleReviewersQuery(): Builder
    {
        Gate::forUser($this->actor())->authorize('create', PaymentReviewerEnrollment::class);
        app(InstallationInstitution::class)->require();

        return User::query()->whereKeyNot($this->actor()->id)->whereNotNull('email_verified_at')
            ->whereHas('roles', function (Builder $query): void {
                $query->where('guard_name', 'web')->whereIn('name', ['finance_officer', 'admin', 'super_admin']);
            })
            ->whereHas('permissions', function (Builder $query): void {
                $query->where('guard_name', 'web')->where('name', 'AuthorizePayment:PaymentIntent');
            });
    }

    /** @return Builder<PaymentReservation> */
    private function reservationsQuery(): Builder
    {
        return PaymentReservation::query()->with(['intent', 'authorization'])
            ->where('organization_id', app(InstallationInstitution::class)->current()?->id);
    }

    private function currentReservation(PaymentReservation $record): PaymentReservation
    {
        Gate::forUser($this->actor())->authorize('create', PaymentIntent::class);
        /** @var PaymentReservation $reservation */
        $reservation = $this->reservationsQuery()->whereKey($record->id)->firstOrFail();
        abort_unless($reservation->intent instanceof PaymentIntent
            && $reservation->intent->id === $reservation->payment_intent_id
            && $reservation->intent->organization_id === $reservation->organization_id, 403);
        Gate::forUser($this->actor())->authorize('view', $reservation->intent);

        return $reservation;
    }

    private function canReview(PaymentReservation $reservation): bool
    {
        $user = Auth::user();

        return $user instanceof User && $reservation->intent instanceof PaymentIntent && ! $this->hasReview($reservation)
            && $user->hasAnyRole(['finance_officer', 'admin', 'super_admin'])
            && $user->checkPermissionTo('AuthorizePayment:PaymentIntent', 'web')
            && $user->hasDirectPermission('AuthorizePayment:PaymentIntent')
            && Gate::forUser($user)->allows('authorizePayment', $reservation->intent);
    }

    private function hasReview(PaymentReservation $reservation): bool
    {
        return $reservation->relationLoaded('authorization')
            ? $reservation->authorization !== null
            : $reservation->authorization()->exists();
    }

    private function authorizeReview(PaymentReservation $reservation): void
    {
        $user = $this->actor();
        abort_unless($user->hasAnyRole(['finance_officer', 'admin', 'super_admin'])
            && $user->checkPermissionTo('AuthorizePayment:PaymentIntent', 'web')
            && $user->hasDirectPermission('AuthorizePayment:PaymentIntent'), 403);
        Gate::forUser($user)->authorize('authorizePayment', $reservation->intent);
    }

    private function mountPaymentEvidence(PaymentReservation $reservation): void
    {
        /** @var PaymentIntent $intent */
        $intent = $reservation->intent;
        Gate::forUser($this->actor())->authorize('view', $intent);
        /** @var FundingWindowApproval|null $approval */
        $approval = FundingWindowApproval::query()->where('organization_id', $reservation->organization_id)
            ->whereKey($reservation->funding_window_approval_id)->first();
        /** @var FundingWindow|null $window */
        $window = $approval === null ? null : FundingWindow::query()->where('organization_id', $reservation->organization_id)
            ->whereKey($approval->funding_window_id)->first();
        $this->mountedIntentId = $intent->id;
        $this->mountedReservationId = $reservation->id;
        $this->mountedIntentDigest = $intent->snapshot_digest;
        $this->mountedReservationDigest = $reservation->snapshot_digest;
        $this->mountedRequestKey = '';
        $this->mountedPaymentEvidence = [
            'organization_id' => $reservation->organization_id, 'invoice_id' => $reservation->invoice_id,
            'invoice_reference' => $intent->snapshot['invoice']['reference'] ?? null,
            'prepared_by' => $intent->prepared_by, 'reserved_by' => $reservation->reserved_by,
            'reservation_key' => $reservation->reservation_key, 'reservation_state' => 'Held — application capacity only',
            'amount' => $this->formatUsdc($intent->amount_base_units), 'max_fee' => $this->formatUsdc($intent->max_fee_base_units),
            'reserved_amount' => $this->formatUsdc($reservation->amount_base_units),
            'reserved_max_fee' => $this->formatUsdc($reservation->max_fee_base_units),
            'source_address' => $intent->source_address, 'recipient_address' => $intent->recipient_address,
            'chain' => $intent->chain, 'chain_id' => $intent->chain_id,
            'is_fake' => $reservation->snapshot['balance_observation']['is_fake'] ?? null,
            'can_execute' => false, 'reserved_intent_digest' => $reservation->snapshot['intent_digest'] ?? null,
            'funding_window_approval_id' => $reservation->funding_window_approval_id,
            'funding_window_id' => $approval?->funding_window_id,
            'funding_approval_digest' => $reservation->snapshot['approval_digest'] ?? null,
            'funding_window_digest' => $approval?->window_digest,
            'funding_valid_until' => $window?->snapshot['valid_until'] ?? null,
            'invoice_review_digest' => $intent->snapshot['invoice_evidence']['review_digest'] ?? null,
            'destination_approval_digest' => $intent->snapshot['vendor_destination']['approval_digest'] ?? null,
            'policy_activation_digest' => $intent->snapshot['policy']['activation_digest'] ?? null,
            'integrity' => $window !== null && $approval !== null && $approval->hasValidEvidence($window)
                && $reservation->hasValidEvidence($intent, $approval) ? 'Intact payment, funding approval and reservation evidence' : 'Evidence integrity failed — review cannot grant authority',
        ];
        $this->mountedReviewEvidence = $reservation->authorization === null ? [] : Arr::only($reservation->authorization->evidence(), [
            'id', 'request_key', 'reviewed_by', 'decision', 'snapshot_digest', 'evidence_valid', 'payment_approved',
            'approval_recorded', 'valid_until', 'mfa_method', 'is_fake', 'authority_scope', 'can_execute', 'reason',
        ]);
    }

    /** @return list<Component> */
    private function paymentEvidenceSchema(): array
    {
        return [
            Section::make('Exact payment and held capacity')->description('No external funds are locked and no local payment state is changed. Every decision keeps the reservation held.')
                ->schema([
                    TextEntry::make('payment_intent_id')->label('Payment intent ID')->state(fn (): ?int => $this->mountedIntentId),
                    TextEntry::make('payment_reservation_id')->label('Reservation ID')->state(fn (): ?int => $this->mountedReservationId),
                    $this->evidenceEntry('organization_id', 'Institution ID'), $this->evidenceEntry('invoice_id', 'Bill ID'),
                    $this->evidenceEntry('invoice_reference', 'Bill reference')->columnSpanFull(),
                    $this->evidenceEntry('prepared_by', 'Prepared by user ID'), $this->evidenceEntry('reserved_by', 'Reserved by user ID'),
                    $this->evidenceEntry('amount', 'Exact payment amount'), $this->evidenceEntry('max_fee', 'Exact fee ceiling'),
                    $this->evidenceEntry('reserved_amount', 'Held payment amount'), $this->evidenceEntry('reserved_max_fee', 'Held fee ceiling'),
                    $this->evidenceEntry('source_address', 'Source treasury')->copyable()->columnSpanFull(),
                    $this->evidenceEntry('recipient_address', 'Recipient')->copyable()->columnSpanFull(),
                    $this->evidenceEntry('chain', 'Chain'), $this->evidenceEntry('chain_id', 'Chain ID'),
                    IconEntry::make('is_fake')->label('Simulation / fake evidence')->boolean()
                        ->state(fn (): mixed => $this->mountedPaymentEvidence['is_fake'] ?? null)->placeholder('Unknown'),
                    IconEntry::make('can_execute')->label('Can execute')->boolean()->state(false),
                    $this->evidenceEntry('reservation_state', 'Reservation state')->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Bound digests and approvals')->schema([
                TextEntry::make('intent_digest')->label('Mounted intent SHA-256')->state(fn (): string => $this->mountedIntentDigest)->wrap()->copyable()->columnSpanFull(),
                TextEntry::make('reservation_digest')->label('Mounted reservation SHA-256')->state(fn (): string => $this->mountedReservationDigest)->wrap()->copyable()->columnSpanFull(),
                $this->evidenceEntry('reserved_intent_digest', 'Intent digest bound by reservation')->copyable()->columnSpanFull(),
                $this->evidenceEntry('reservation_key', 'Reservation key (UUID)')->copyable()->columnSpanFull(),
                $this->evidenceEntry('funding_window_id', 'Funding window ID'), $this->evidenceEntry('funding_window_approval_id', 'Funding approval ID'),
                $this->evidenceEntry('funding_valid_until', 'Funding window expiry (UTC)')->columnSpanFull(),
                $this->evidenceEntry('funding_window_digest', 'Approved funding window SHA-256')->copyable()->columnSpanFull(),
                $this->evidenceEntry('funding_approval_digest', 'Funding approval SHA-256 bound by reservation')->copyable()->columnSpanFull(),
                $this->evidenceEntry('invoice_review_digest', 'Bill review SHA-256')->copyable()->columnSpanFull(),
                $this->evidenceEntry('destination_approval_digest', 'Recipient approval SHA-256')->copyable()->columnSpanFull(),
                $this->evidenceEntry('policy_activation_digest', 'Finance policy activation SHA-256')->copyable()->columnSpanFull(),
                $this->evidenceEntry('integrity', 'Evidence integrity')->columnSpanFull(),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
            Section::make('Recorded payment review — never execution')->visible(fn (): bool => $this->mountedReviewEvidence !== [])
                ->schema([
                    $this->reviewEntry('id', 'Payment review ID'), $this->reviewEntry('reviewed_by', 'Reviewed by user ID'),
                    $this->reviewEntry('decision', 'Decision')->formatStateUsing(fn (string $state): string => self::DECISIONS[$state] ?? $state)->columnSpanFull(),
                    $this->reviewEntry('reason', 'Review reason')->columnSpanFull(),
                    $this->reviewEntry('request_key', 'Recorded request key (UUID)')->copyable()->columnSpanFull(),
                    $this->reviewEntry('snapshot_digest', 'Payment review SHA-256')->copyable()->columnSpanFull(),
                    $this->reviewEntry('valid_until', 'Recorded authority expiry (UTC)'), $this->reviewEntry('mfa_method', 'MFA method'),
                    $this->reviewEntry('authority_scope', 'Recorded authority scope')->columnSpanFull(),
                    ...array_map(fn (string $field): IconEntry => IconEntry::make('review_'.$field)->label(Str::headline($field))->boolean()
                        ->state(fn (): mixed => $this->mountedReviewEvidence[$field] ?? null),
                        ['evidence_valid', 'approval_recorded', 'payment_approved', 'is_fake', 'can_execute']),
                ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
        ];
    }

    /** @return list<Component> */
    private function reviewSchema(): array
    {
        $rules = ReviewVendorPayment::inputRules();

        return [
            Section::make('Independent payment review')->description('Approval records bounded authority, not a transfer. Fake evidence is simulation only. Rejection or hold does not release reserved capacity.')
                ->schema([
                    TextEntry::make('request_key')->label('Server-generated review request key (UUID)')
                        ->state(fn (): string => $this->mountedRequestKey)->wrap()->copyable()->columnSpanFull(),
                    Select::make('decision')->options([
                        'approve_payment' => 'Approve exact payment authority — executor disabled',
                        'reject_payment' => 'Reject payment — keep reservation held',
                        'hold_payment' => 'Hold payment — keep reservation held',
                    ])->required()->rules($rules['decision'])->live()->columnSpanFull(),
                    Textarea::make('reason')->label('Review reason')->required()->rules($rules['reason'])->maxLength(1000)->rows(3)->columnSpanFull(),
                    TextInput::make('valid_until')->label('Authority valid until (UTC)')->placeholder('2026-10-10T08:03:00+00:00')
                        ->visible(fn (Get $get): bool => $get('decision') === 'approve_payment')
                        ->required(fn (Get $get): bool => $get('decision') === 'approve_payment')
                        ->rules($rules['valid_until'])
                        ->helperText('Use Y-m-dTH:i:s+00:00. Approval must expire within five minutes and no later than the reviewed funding window.')
                        ->columnSpanFull(),
                    TextInput::make('password')->label('Current password')->password()->autocomplete('current-password')
                        ->required()->rules($rules['password']),
                    Select::make('mfa_method')->label('Authenticator method')->options([
                        'filament_app' => 'Filament authenticator app', 'fortify_totp' => 'Fortify TOTP',
                    ])->required()->rules($rules['mfa_method']),
                    TextInput::make('mfa_code')->label('Six-digit authenticator code')->password()->inputMode('numeric')->autocomplete('one-time-code')
                        ->required()->rules($rules['mfa_code'])->minLength(6)->maxLength(6)
                        ->helperText('Use the configured authenticator, including any leading zero. Email and recovery codes are not accepted.'),
                    Checkbox::make('attestation')
                        ->label('I reviewed these exact payment and reservation IDs, amounts, source, recipient, chain and digests. My decision records authority only, not a transfer. Execution stays disabled, no local payment state changes, and rejection or hold keeps the reservation held.')
                        ->required()->accepted()->columnSpanFull(),
                ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
        ];
    }

    private function defaultApprovalExpiry(): ?string
    {
        $fundingExpiry = $this->mountedPaymentEvidence['funding_valid_until'] ?? null;
        if (! is_string($fundingExpiry)) {
            return null;
        }
        $windowExpiry = Carbon::parse($fundingExpiry)->utc();
        $defaultExpiry = now()->utc()->addMinutes(3);

        return ($windowExpiry->lt($defaultExpiry) ? $windowExpiry : $defaultExpiry)->toIso8601String();
    }

    private function evidenceEntry(string $key, string $label): TextEntry
    {
        return TextEntry::make($key)->label($label)->state(fn (): mixed => $this->mountedPaymentEvidence[$key] ?? null)->wrap()->placeholder('Not recorded');
    }

    private function reviewEntry(string $key, string $label): TextEntry
    {
        return TextEntry::make('review_'.$key)->label($label)->state(fn (): mixed => $this->mountedReviewEvidence[$key] ?? null)->wrap()->placeholder('Not recorded');
    }

    private function formatUsdc(int $minorUnits): string
    {
        return (new Money($minorUnits, CurrencyCode::USDC))->format().' USDC';
    }

    private function actor(): User
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }
}
