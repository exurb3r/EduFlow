<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students;

use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Filament\Resources\Students\Pages\ViewStudent;
use App\Filament\Resources\Students\RelationManagers\AssistanceRequestsRelationManager;
use App\Filament\Resources\Students\RelationManagers\StudentBillsRelationManager;
use App\Filament\Resources\Students\RelationManagers\TuitionAccountsRelationManager;
use App\Filament\Resources\Students\Schemas\StudentInfolist;
use App\Models\Student;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::AcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Student Services';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'student_number';

    public static function userSelect(): Select
    {
        return Select::make('user_id')
            ->label('User account')
            ->relationship('user', 'name')
            ->getOptionLabelFromRecordUsing(
                fn (User $record): string => "{$record->name} — {$record->email}",
            )
            ->searchable(['name', 'email'])
            ->preload()
            ->required()
            ->unique(table: Student::class, column: 'user_id', ignoreRecord: true)
            ->helperText('Select the linked portal authentication account.');
    }

    public static function studentNumberInput(): TextInput
    {
        return TextInput::make('student_number')
            ->label('Student number')
            ->required()
            ->maxLength(255)
            ->unique(table: Student::class, column: 'student_number', ignoreRecord: true)
            ->placeholder('STU-000001')
            ->helperText('Institutional unique identification number.');
    }

    public static function programInput(): TextInput
    {
        return TextInput::make('program')
            ->label('Degree program')
            ->required()
            ->maxLength(255)
            ->placeholder('BS Information Technology')
            ->helperText('e.g. BS Information Technology, BS Computer Science');
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
            ->required()
            ->default('enrolled')
            ->helperText('Current registration status.');
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
            ->required()
            ->default('qualified')
            ->helperText('Institutional qualification standing.');
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
            ->helperText('Average class attendance (0 to 100%).');
    }

    public static function payoutAddressInput(): TextInput
    {
        return TextInput::make('payout_address')
            ->label('Payout wallet address')
            ->placeholder('0x…')
            ->rule('regex:'.Student::PAYOUT_ADDRESS_PATTERN)
            ->helperText('0x followed by exactly 40 hex characters. Arc address for automated disbursements.');
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
                Section::make('Student Identity & Account')
                    ->description('Links this student profile to a user account and institutional ID.')
                    ->icon(Heroicon::User)
                    ->schema([
                        self::userSelect(),
                        self::studentNumberInput(),
                    ])
                    ->columns(2),

                Section::make('Academic Standing & Curriculum')
                    ->description('Degree program, curriculum year, enrollment, and academic status.')
                    ->icon(Heroicon::AcademicCap)
                    ->schema([
                        self::programInput(),
                        self::yearLevelInput(),
                        self::attendanceRateInput(),
                        self::enrollmentStatusSelect(),
                        self::academicStatusSelect(),
                    ])
                    ->columns(3),

                Section::make('On-Chain Payout Wallet')
                    ->description('Circle Agent Stack wallet address on Arc Network for disbursements.')
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
            ->columns([
                TextColumn::make('student_number')
                    ->label('Student no.')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->copyable()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('user.name')
                    ->label('Student')
                    ->searchable(['users.name', 'users.email'])
                    ->description(fn (Student $record): string => $record->user->email)
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('program')
                    ->label('Program')
                    ->searchable()
                    ->description(fn (Student $record): string => "Year {$record->year_level}")
                    ->toggleable(),

                TextColumn::make('year_level')
                    ->label('Year')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('enrollment_status')
                    ->label('Enrollment')
                    ->badge()
                    ->color(fn (Student $record): string => $record->enrollmentStatusColor())
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable(),

                TextColumn::make('academic_status')
                    ->label('Academic')
                    ->badge()
                    ->color(fn (Student $record): string => $record->academicStatusColor())
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable(),

                TextColumn::make('attendance_rate')
                    ->label('Attendance')
                    ->formatStateUsing(fn ($state): string => $state !== null ? "{$state}%" : '—')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('payout_address')
                    ->label('Wallet')
                    ->fontFamily('mono')
                    ->copyable()
                    ->toggleable()
                    ->limit(16)
                    ->placeholder('Not set')
                    ->tooltip(fn (?string $state): string => match (true) {
                        $state === null || $state === '' => 'No payout wallet: this student cannot receive assistance.',
                        preg_match(Student::PAYOUT_ADDRESS_PATTERN, $state) !== 1 => 'Stored address is malformed: payments to this student are blocked.',
                        default => "Arc wallet: {$state}",
                    }),

                TextColumn::make('tuitionAccounts_count')
                    ->label('Tuition Terms')
                    ->counts('tuitionAccounts')
                    ->badge()
                    ->color('primary')
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
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
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
