<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Filament\Resources\Students\Pages\ViewStudent;
use App\Filament\Resources\Students\RelationManagers\AssistanceRequestsRelationManager;
use App\Filament\Resources\Students\RelationManagers\StudentBillsRelationManager;
use App\Filament\Resources\Students\RelationManagers\TuitionAccountsRelationManager;
use App\Filament\Resources\Students\Schemas\StudentInfolist;
use App\Filament\Resources\TuitionAccounts\TuitionAccountResource;
use App\Models\AcademicTerm;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use UnitEnum;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::AcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Student Services';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'student_number';

    public static function getNavigationBadge(): ?string
    {
        $count = Student::query()->where('enrollment_status', 'enrolled')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    public static function userSelect(): Select
    {
        return Select::make('user_id')
            ->label('Linked user account')
            ->relationship('user', 'name')
            ->getOptionLabelFromRecordUsing(
                fn (User $record): string => "{$record->name} — {$record->email}",
            )
            ->searchable(['name', 'email'])
            ->preload()
            ->required()
            ->unique(table: Student::class, column: 'user_id', ignoreRecord: true)
            ->prefixIcon(Heroicon::User)
            ->helperText('Select the authentication user profile backing this student.');
    }

    public static function studentNumberInput(): TextInput
    {
        return TextInput::make('student_number')
            ->label('Student number')
            ->required()
            ->maxLength(255)
            ->unique(table: Student::class, column: 'student_number', ignoreRecord: true)
            ->placeholder('STU-000001')
            ->prefixIcon(Heroicon::Identification)
            ->helperText('Institutional unique identification number.');
    }

    public static function programInput(): TextInput
    {
        return TextInput::make('program')
            ->label('Degree program')
            ->required()
            ->maxLength(255)
            ->placeholder('BS Information Technology')
            ->prefixIcon(Heroicon::BookOpen)
            ->datalist([
                'BS Information Technology',
                'BS Computer Science',
                'BS Information Systems',
                'BS Data Science',
                'BS Software Engineering',
                'BS Business Administration',
            ])
            ->helperText('Degree curriculum (e.g. BS Information Technology, BS Computer Science).');
    }

    public static function yearLevelInput(): TextInput
    {
        return TextInput::make('year_level')
            ->label('Year level')
            ->required()
            ->numeric()
            ->minValue(1)
            ->maxValue(10)
            ->rules(['integer'])
            ->prefixIcon(Heroicon::AcademicCap)
            ->helperText('Current curriculum year level (1 to 10).');
    }

    public static function enrollmentStatusSelect(): Select
    {
        return Select::make('enrollment_status')
            ->label('Enrollment status')
            ->options([
                'enrolled' => 'Enrolled',
                'not_enrolled' => 'Not enrolled',
                'on_leave' => 'On leave',
                'graduated' => 'Graduated',
            ])
            ->native(false)
            ->required()
            ->default('enrolled')
            ->helperText('Current registration standing with the institution.');
    }

    public static function academicStatusSelect(): Select
    {
        return Select::make('academic_status')
            ->label('Academic status')
            ->options([
                'qualified' => 'Qualified',
                'probation' => 'Probation',
                'suspended' => 'Suspended',
                'dismissed' => 'Dismissed',
            ])
            ->native(false)
            ->required()
            ->default('qualified')
            ->helperText('Institutional standing; determines financial assistance eligibility.');
    }

    public static function attendanceRateInput(): TextInput
    {
        return TextInput::make('attendance_rate')
            ->label('Attendance rate (%)')
            ->numeric()
            ->minValue(0)
            ->maxValue(100)
            ->suffix('%')
            ->placeholder('95.00')
            ->prefixIcon(Heroicon::Calendar)
            ->helperText('Average class attendance (0.00% to 100.00%). Minimum 80% for grant qualification.');
    }

    public static function payoutAddressInput(): TextInput
    {
        return TextInput::make('payout_address')
            ->label('Payout wallet address')
            ->placeholder('0x…')
            ->rule('regex:'.Student::PAYOUT_ADDRESS_PATTERN)
            ->prefixIcon(Heroicon::Wallet)
            ->helperText('0x followed by exactly 40 hex characters. Arc Network address for automated USDC disbursements.');
    }

    /**
     * Shared field set, reused by the resource form and by the inline
     * "New student" option form on the tuition account picker.
     *
     * @return array<int, Select|TextInput>
     */
    public static function formFields(): array
    {
        return [
            self::userSelect(),
            self::studentNumberInput(),
            self::programInput(),
            self::yearLevelInput(),
            self::enrollmentStatusSelect(),
            self::academicStatusSelect(),
            self::attendanceRateInput(),
            self::payoutAddressInput(),
        ];
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Student Identity & Account Linking')
                    ->description('Connect this student record with a portal account and institutional ID.')
                    ->icon(Heroicon::UserCircle)
                    ->schema([
                        self::userSelect(),
                        self::studentNumberInput(),
                    ])
                    ->columns(2),

                Section::make('Academic Standing & Curriculum')
                    ->description('Curriculum program, year level standing, attendance rate, and qualification.')
                    ->icon(Heroicon::AcademicCap)
                    ->schema([
                        self::programInput(),
                        self::yearLevelInput(),
                        self::attendanceRateInput(),
                        self::enrollmentStatusSelect(),
                        self::academicStatusSelect(),
                    ])
                    ->columns(3),

                Section::make('On-Chain Arc Settlement Wallet')
                    ->description('USDC recipient destination on Arc Network (Chain ID: 5042002) for disbursements and aid.')
                    ->icon(Heroicon::Wallet)
                    ->schema([
                        self::payoutAddressInput(),
                    ])
                    ->columns(1),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'tuitionAccounts']))
            ->emptyStateHeading('No students found')
            ->emptyStateDescription('Register a student profile to track tuition, aid eligibility, and Arc disbursement wallets.')
            ->emptyStateIcon(Heroicon::AcademicCap)
            ->emptyStateActions([
                CreateAction::make()
                    ->label('Register student')
                    ->icon(Heroicon::PlusCircle),
            ])
            ->columns([
                TextColumn::make('student_number')
                    ->label('Student no.')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->copyable()
                    ->badge()
                    ->color('gray')
                    ->icon(Heroicon::Identification),

                TextColumn::make('user.name')
                    ->label('Student')
                    ->searchable(['users.name', 'users.email'])
                    ->description(fn (Student $record): string => $record->user->email)
                    ->weight('bold')
                    ->icon(Heroicon::User)
                    ->sortable(),

                TextColumn::make('program')
                    ->label('Program')
                    ->searchable()
                    ->description(fn (Student $record): string => "Year {$record->year_level}")
                    ->icon(Heroicon::BookOpen)
                    ->toggleable(),

                TextColumn::make('year_level')
                    ->label('Year')
                    ->formatStateUsing(fn ($state): string => "Year {$state}")
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('enrollment_status')
                    ->label('Enrollment')
                    ->badge()
                    ->color(fn (Student $record): string => $record->enrollmentStatusColor())
                    ->icon(fn (string $state): string => match ($state) {
                        'enrolled' => 'heroicon-m-check-circle',
                        'on_leave' => 'heroicon-m-pause-circle',
                        'graduated' => 'heroicon-m-academic-cap',
                        default => 'heroicon-m-x-circle',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable(),

                TextColumn::make('academic_status')
                    ->label('Academic')
                    ->badge()
                    ->color(fn (Student $record): string => $record->academicStatusColor())
                    ->icon(fn (string $state): string => match ($state) {
                        'qualified' => 'heroicon-m-check-badge',
                        'probation' => 'heroicon-m-exclamation-triangle',
                        'suspended', 'dismissed' => 'heroicon-m-shield-exclamation',
                        default => 'heroicon-m-information-circle',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable(),

                TextColumn::make('attendance_rate')
                    ->label('Attendance')
                    ->formatStateUsing(fn ($state): string => $state !== null ? "{$state}%" : '—')
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        $state === null => 'gray',
                        (float) $state >= 90.0 => 'success',
                        (float) $state >= 75.0 => 'warning',
                        default => 'danger',
                    })
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('payout_address')
                    ->label('Wallet')
                    ->fontFamily('mono')
                    ->copyable()
                    ->toggleable()
                    ->limit(16)
                    ->placeholder('Not set')
                    ->color(fn (?string $state): string => filled($state) ? 'gray' : 'danger')
                    ->icon(fn (?string $state): string => filled($state) ? 'heroicon-m-wallet' : 'heroicon-m-exclamation-circle')
                    ->tooltip(fn (?string $state): string => match (true) {
                        $state === null || $state === '' => 'No payout wallet: this student cannot receive assistance.',
                        preg_match(Student::PAYOUT_ADDRESS_PATTERN, $state) !== 1 => 'Stored address is malformed: payments to this student are blocked.',
                        default => "Arc wallet: {$state}",
                    }),

                TextColumn::make('tuition_balance')
                    ->label('Tuition Balance')
                    ->state(fn (Student $record): string => (new Money($record->totalTuitionBalance(), CurrencyCode::USDC))->format(2).' USDC')
                    ->badge()
                    ->color(fn (Student $record): string => $record->totalTuitionBalance() > 0 ? 'danger' : 'success')
                    ->icon(fn (Student $record): string => $record->totalTuitionBalance() > 0 ? 'heroicon-m-exclamation-circle' : 'heroicon-m-check-circle')
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('tuitionAccounts_count')
                    ->label('Tuition Terms')
                    ->counts('tuitionAccounts')
                    ->badge()
                    ->color('primary')
                    ->icon(Heroicon::Banknotes)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('enrollment_status')
                    ->label('Enrollment')
                    ->options([
                        'enrolled' => 'Enrolled',
                        'not_enrolled' => 'Not enrolled',
                        'on_leave' => 'On leave',
                        'graduated' => 'Graduated',
                    ]),

                SelectFilter::make('academic_status')
                    ->label('Academic Standing')
                    ->options([
                        'qualified' => 'Qualified',
                        'probation' => 'Probation',
                        'suspended' => 'Suspended',
                        'dismissed' => 'Dismissed',
                    ]),

                SelectFilter::make('year_level')
                    ->label('Year Level')
                    ->options([
                        1 => 'Year 1',
                        2 => 'Year 2',
                        3 => 'Year 3',
                        4 => 'Year 4',
                        5 => 'Year 5',
                    ]),

                TernaryFilter::make('has_payout_address')
                    ->label('Payout Wallet')
                    ->placeholder('All students')
                    ->trueLabel('Wallet assigned')
                    ->falseLabel('Missing wallet')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('payout_address')->where('payout_address', '!=', ''),
                        false: fn (Builder $query): Builder => $query->where(fn ($q) => $q->whereNull('payout_address')->orWhere('payout_address', '')),
                    ),

                TernaryFilter::make('has_tuition_debt')
                    ->label('Tuition Debt')
                    ->placeholder('All accounts')
                    ->trueLabel('Has outstanding debt')
                    ->falseLabel('Tuition settled')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('tuitionAccounts', fn (Builder $q) => $q->whereColumn('total_amount', '>', 'paid_amount')),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('tuitionAccounts', fn (Builder $q) => $q->whereColumn('total_amount', '>', 'paid_amount')),
                    ),

                Filter::make('low_attendance')
                    ->label('Low attendance (<80%)')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('attendance_rate')->where('attendance_rate', '<', 80)),
            ])
            ->recordActions([
                ViewAction::make()
                    ->icon(Heroicon::Eye),
                EditAction::make()
                    ->icon(Heroicon::PencilSquare),
                Action::make('createTuitionAccount')
                    ->label('Add term')
                    ->icon('heroicon-m-banknotes')
                    ->color('primary')
                    ->visible(fn (): bool => Gate::allows('create', TuitionAccount::class))
                    ->modalHeading('Create Tuition Account')
                    ->modalDescription('Open a tuition account for this student for a selected academic term.')
                    ->modalSubmitActionLabel('Create account')
                    ->schema([
                        Select::make('academic_term_id')
                            ->label('Academic Term')
                            ->options(fn (): array => AcademicTerm::query()->pluck('name', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->required(),

                        TextInput::make('total_amount')
                            ->label('Total tuition (USDC)')
                            ->prefix('USDC')
                            ->inputMode('decimal')
                            ->placeholder('300.00')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1000000000)
                            ->rules(['regex:/^\d+(\.\d{1,6})?$/']),

                        TextInput::make('paid_amount')
                            ->label('Amount paid (USDC)')
                            ->prefix('USDC')
                            ->inputMode('decimal')
                            ->placeholder('0.00')
                            ->default('0.00')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1000000000)
                            ->rules(['regex:/^\d+(\.\d{1,6})?$/']),
                    ])
                    ->action(function (array $data, Student $record): void {
                        $totalUnits = Money::fromDecimal((string) $data['total_amount'], CurrencyCode::USDC)->toBaseUnits();
                        $paidUnits = Money::fromDecimal((string) $data['paid_amount'], CurrencyCode::USDC)->toBaseUnits();

                        TuitionAccountResource::assertUniqueAccount([
                            'student_id' => $record->id,
                            'academic_term_id' => $data['academic_term_id'],
                        ]);

                        $account = TuitionAccount::query()->create([
                            'student_id' => $record->id,
                            'academic_term_id' => $data['academic_term_id'],
                            'total_amount' => $totalUnits,
                            'paid_amount' => $paidUnits,
                        ]);

                        Notification::make()
                            ->title('Tuition account created')
                            ->body("Created tuition account #{$account->id} for {$record->user->name}.")
                            ->success()
                            ->send();
                    }),
                Action::make('arcscan')
                    ->label('ArcScan')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (Student $record): bool => filled($record->payout_address))
                    ->url(fn (Student $record): ?string => $record->payout_address ? app(ArcNetworkGateway::class)->addressExplorerUrl($record->payout_address) : null)
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('updateEnrollmentStatus')
                        ->label('Update enrollment status')
                        ->icon(Heroicon::AcademicCap)
                        ->schema([
                            Select::make('enrollment_status')
                                ->label('New enrollment status')
                                ->options([
                                    'enrolled' => 'Enrolled',
                                    'not_enrolled' => 'Not enrolled',
                                    'on_leave' => 'On leave',
                                    'graduated' => 'Graduated',
                                ])
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $count = $records->count();
                            foreach ($records as $record) {
                                $record->update(['enrollment_status' => $data['enrollment_status']]);
                            }

                            Notification::make()
                                ->title('Enrollment status updated')
                                ->body("Updated status for {$count} students to ".ucfirst(str_replace('_', ' ', $data['enrollment_status'])).'.')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make()
                        ->before(function (DeleteBulkAction $action): void {
                            $blocked = $action->getSelectedRecords()
                                ->filter(fn (Student $student): bool => $student->deletionBlockedReason() !== null);

                            if ($blocked->isEmpty()) {
                                return;
                            }

                            $names = $blocked->pluck('student_number')->join(', ');

                            Notification::make()
                                ->title('Cannot delete these students')
                                ->body("They have financial history on file: {$names}. Deselect them and try again — deleting a student must not erase tuition or assistance records.")
                                ->danger()
                                ->send();

                            throw new Halt;
                        }),
                ]),
            ]);
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return StudentInfolist::configure($schema);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            TuitionAccountsRelationManager::class,
            AssistanceRequestsRelationManager::class,
            StudentBillsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListStudents::route('/'),
            'create' => CreateStudent::route('/create'),
            'edit' => EditStudent::route('/{record}/edit'),
            'view' => ViewStudent::route('/{record}'),
        ];
    }
}
