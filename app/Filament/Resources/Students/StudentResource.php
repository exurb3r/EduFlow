<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students;

use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\Pages\ListStudents;
use App\Filament\Resources\Students\Pages\ViewStudent;
use App\Models\Student;
use App\Models\User;
use BackedEnum;
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
use Filament\Tables\Table;
use UnitEnum;

class StudentResource extends Resource
{
    protected static ?string $model = Student::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::AcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Student Services';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'student_number';

    /**
     * Shared field set, reused by the resource form and by the inline
     * "New student" option form on the tuition account picker.
     *
     * @return array<int, Select|TextInput>
     */
    public static function formFields(): array
    {
        return [
            Select::make('user_id')
                ->label('User account')
                ->relationship('user', 'name')
                ->getOptionLabelFromRecordUsing(
                    fn (User $record): string => "{$record->name} — {$record->email}",
                )
                ->searchable(['name', 'email'])
                ->preload()
                ->required()
                ->unique(table: Student::class, column: 'user_id', ignoreRecord: true),
            TextInput::make('student_number')
                ->required()
                ->maxLength(255)
                ->unique(table: Student::class, column: 'student_number', ignoreRecord: true)
                ->placeholder('STU-000001'),
            TextInput::make('program')
                ->required()
                ->maxLength(255)
                ->placeholder('BS Information Technology'),
            TextInput::make('year_level')
                ->label('Year level')
                ->required()
                ->numeric()
                ->minValue(1)
                ->maxValue(10)
                ->rules(['integer']),
            Select::make('enrollment_status')
                ->options([
                    'enrolled' => 'Enrolled',
                    'not_enrolled' => 'Not enrolled',
                    'on_leave' => 'On leave',
                    'graduated' => 'Graduated',
                ])
                ->required()
                ->default('enrolled'),
            Select::make('academic_status')
                ->options([
                    'qualified' => 'Qualified',
                    'probation' => 'Probation',
                    'suspended' => 'Suspended',
                    'dismissed' => 'Dismissed',
                ])
                ->required()
                ->default('qualified'),
            TextInput::make('attendance_rate')
                ->label('Attendance rate (%)')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->placeholder('95.00'),
            TextInput::make('payout_address')
                ->label('Payout wallet address')
                ->placeholder('0x…')
                ->rule('regex:'.Student::PAYOUT_ADDRESS_PATTERN),
        ];
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Student record')
                    ->description('Links an account to a student identity. The tuition balance itself is set under Tuition Accounts.')
                    ->components(self::formFields())
                    ->columns(2),
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
                    ->fontFamily('mono'),
                TextColumn::make('user.name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('program')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('year_level')
                    ->label('Year')
                    ->sortable(),
                TextColumn::make('enrollment_status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('academic_status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('payout_address')
                    ->label('Wallet')
                    ->fontFamily('mono')
                    ->copyable()
                    ->toggleable()
                    ->limit(24)
                    ->placeholder('Not set')
                    ->tooltip(fn (?string $state): string => match (true) {
                        $state === null || $state === '' => 'No payout wallet: this student cannot receive assistance.',
                        preg_match(Student::PAYOUT_ADDRESS_PATTERN, $state) !== 1 => 'Stored address is malformed: payments to this student are blocked.',
                        default => $state,
                    }),
                TextColumn::make('tuitionAccounts_count')
                    ->label('Accounts')
                    ->counts('tuitionAccounts')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
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
