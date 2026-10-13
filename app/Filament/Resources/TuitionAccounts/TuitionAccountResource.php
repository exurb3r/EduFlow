<?php

declare(strict_types=1);

namespace App\Filament\Resources\TuitionAccounts;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\TuitionAccounts\Pages\CreateTuitionAccount;
use App\Filament\Resources\TuitionAccounts\Pages\EditTuitionAccount;
use App\Filament\Resources\TuitionAccounts\Pages\ListTuitionAccounts;
use App\Filament\Resources\TuitionAccounts\Pages\ViewTuitionAccount;
use App\Filament\Resources\TuitionAccounts\RelationManagers\PaymentAttemptsRelationManager;
use App\Filament\Resources\TuitionAccounts\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\TuitionAccounts\Schemas\TuitionAccountInfolist;
use App\Models\Student;
use App\Models\TuitionAccount;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnitEnum;

class TuitionAccountResource extends Resource
{
    protected static ?string $model = TuitionAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Banknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Student Services';

    protected static ?int $navigationSort = 2;

    /**
     * Amounts are stored as integer USDC base units (6 decimals). The form
     * works in human USDC decimals and converts exactly via Money — floats
     * never touch tuition figures.
     */
    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tuition account')
                    ->components([
                        Select::make('student_id')
                            ->label('Student')
                            ->relationship('student', 'student_number')
                            ->getOptionLabelFromRecordUsing(
                                fn (Student $record): string => "{$record->student_number} — {$record->user->name}",
                            )
                            ->getSearchResultsUsing(function (string $search): array {
                                $options = [];

                                $students = Student::query()
                                    ->with('user')
                                    ->where('student_number', 'like', "%{$search}%")
                                    ->orWhereHas('user', fn ($query) => $query->where('name', 'like', "%{$search}%"))
                                    ->limit(50)
                                    ->get();

                                foreach ($students as $student) {
                                    $options[$student->id] = "{$student->student_number} — {$student->user->name}";
                                }

                                return $options;
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->createOptionForm(fn (): array => StudentResource::formFields())
                            ->createOptionModalHeading('New student'),
                        Select::make('academic_term_id')
                            ->label('Academic term')
                            ->relationship('academicTerm', 'name')
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
                            ->rules(['regex:/^\d+(\.\d{1,6})?$/'])
                            ->formatStateUsing(
                                fn ($state): ?string => $state === null
                                    ? null
                                    : new Money((int) $state, CurrencyCode::USDC)->decimal(),
                            )
                            ->dehydrateStateUsing(
                                fn ($state): int => Money::fromDecimal((string) $state, CurrencyCode::USDC)->toBaseUnits(),
                            ),
                        TextInput::make('paid_amount')
                            ->label('Amount paid (USDC)')
                            ->prefix('USDC')
                            ->inputMode('decimal')
                            ->placeholder('0.00')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1000000000)
                            ->rules([
                                'regex:/^\d+(\.\d{1,6})?$/',
                                fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                    try {
                                        $paid = Money::fromDecimal((string) $value, CurrencyCode::USDC);
                                        $total = Money::fromDecimal((string) ($get('total_amount') ?? '0'), CurrencyCode::USDC);
                                    } catch (InvalidArgumentException) {
                                        return;
                                    }

                                    if ($paid->minorUnits > $total->minorUnits) {
                                        $fail('Paid amount cannot exceed total tuition.');
                                    }
                                },
                            ])
                            ->formatStateUsing(
                                fn ($state): ?string => $state === null
                                    ? null
                                    : new Money((int) $state, CurrencyCode::USDC)->decimal(),
                            )
                            ->dehydrateStateUsing(
                                fn ($state): int => Money::fromDecimal((string) $state, CurrencyCode::USDC)->toBaseUnits(),
                            ),
                    ])
                    ->columns(2),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('student.student_number')
                    ->label('Student no.')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),
                TextColumn::make('student.user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('academicTerm.name')
                    ->label('Term')
                    ->badge()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(
                        fn ($state): string => new Money((int) $state, CurrencyCode::USDC)->format(2).' USDC',
                    )
                    ->weight('bold')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('paid_amount')
                    ->label('Paid')
                    ->formatStateUsing(
                        fn ($state): string => new Money((int) $state, CurrencyCode::USDC)->format(2).' USDC',
                    )
                    ->color('success')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('remaining')
                    ->label('Remaining')
                    ->getStateUsing(
                        fn (TuitionAccount $record): string => new Money($record->remainingAmount(), CurrencyCode::USDC)->format(2).' USDC',
                    )
                    ->weight('bold')
                    ->alignEnd(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('M d, Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('academic_term_id')
                    ->label('Term')
                    ->relationship('academicTerm', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * One account per student per term. Runs on dehydrated base-unit data in
     * the create/edit pages so the form-level decimal inputs stay simple. The
     * error is keyed under the form state path so Livewire surfaces it on the
     * student field.
     *
     * @param  array<string, mixed>  $data
     */
    public static function assertUniqueAccount(array $data, ?int $ignoreId = null): void
    {
        $exists = TuitionAccount::query()
            ->where('student_id', $data['student_id'])
            ->where('academic_term_id', $data['academic_term_id'])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'data.student_id' => 'This student already has a tuition account for the selected term.',
            ]);
        }
    }

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return TuitionAccountInfolist::configure($schema);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
            PaymentAttemptsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListTuitionAccounts::route('/'),
            'create' => CreateTuitionAccount::route('/create'),
            'edit' => EditTuitionAccount::route('/{record}/edit'),
            'view' => ViewTuitionAccount::route('/{record}'),
        ];
    }
}
