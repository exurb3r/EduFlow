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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;
use UnitEnum;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class TuitionAccountResource extends Resource
{
    protected static ?string $model = TuitionAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Banknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Student Services';

    protected static ?int $navigationSort = 2;

    public static function getNavigationBadge(): ?string
    {
        $count = TuitionAccount::query()->whereColumn('total_amount', '>', 'paid_amount')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    #[\Override]
    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Tuition accounts with outstanding balance';
    }

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
                Section::make('Student & Academic Term Assignment')
                    ->description('Select the enrolled student and target academic session term.')
                    ->icon(Heroicon::AcademicCap)
                    ->schema([
                        Select::make('student_id')
                            ->label('Student')
                            ->relationship('student', 'student_number')
                            ->getOptionLabelFromRecordUsing(
                                fn (Student $record): string => "{$record->student_number} — {$record->user->name} ({$record->program})",
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
                                    $options[$student->id] = "{$student->student_number} — {$student->user->name} ({$student->program})";
                                }

                                return $options;
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->prefixIcon(Heroicon::User)
                            ->createOptionForm(fn (): array => StudentResource::formFields())
                            ->createOptionModalHeading('New student profile'),

                        Select::make('academic_term_id')
                            ->label('Academic Term')
                            ->relationship('academicTerm', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->prefixIcon(Heroicon::CalendarDays),
                    ])
                    ->columns(2),

                Section::make('Tuition Ledger & Assessment (USDC)')
                    ->description('Exact 6-decimal USDC tuition amounts recorded on Arc Network.')
                    ->icon(Heroicon::Banknotes)
                    ->schema([
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
                            )
                            ->helperText('Institutional tuition fee assessed for this term.')
                            ->prefixIcon(Heroicon::CurrencyDollar),

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
                            )
                            ->helperText('Total payments already credited towards this term.')
                            ->prefixIcon(Heroicon::CheckCircle),
                    ])
                    ->columns(2),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['student.user', 'student.wallet', 'academicTerm']))
            ->columns([
                TextColumn::make('student.student_number')
                    ->label('Student no.')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->icon(Heroicon::Identification),

                TextColumn::make('student.user.name')
                    ->label('Student')
                    ->searchable(['users.name', 'users.email'])
                    ->description(fn (TuitionAccount $record): string => "{$record->student->program} • Year {$record->student->year_level}")
                    ->weight('bold')
                    ->icon(Heroicon::User)
                    ->sortable(),

                TextColumn::make('academicTerm.name')
                    ->label('Term')
                    ->badge()
                    ->color('info')
                    ->icon(Heroicon::CalendarDays)
                    ->sortable(),

                TextColumn::make('total_amount')
                    ->label('Total Tuition')
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
                    ->weight('bold')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('remaining')
                    ->label('Remaining')
                    ->getStateUsing(
                        fn (TuitionAccount $record): string => new Money($record->remainingAmount(), CurrencyCode::USDC)->format(2).' USDC',
                    )
                    ->weight('bold')
                    ->color(fn (TuitionAccount $record): string => $record->remainingAmount() > 0 ? 'danger' : 'success')
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (TuitionAccount $record): string => $record->statusLabel())
                    ->color(fn (TuitionAccount $record): string => $record->statusColor())
                    ->icon(fn (TuitionAccount $record): string => match (true) {
                        $record->isPaidInFull() => 'heroicon-m-check-circle',
                        $record->isPartiallyPaid() => 'heroicon-m-clock',
                        default => 'heroicon-m-exclamation-circle',
                    }),

                TextColumn::make('progress')
                    ->label('Settled')
                    ->state(fn (TuitionAccount $record): string => $record->paymentProgressPercentage().'%')
                    ->badge()
                    ->color(fn (TuitionAccount $record): string => match (true) {
                        $record->isPaidInFull() => 'success',
                        $record->isPartiallyPaid() => 'warning',
                        default => 'gray',
                    })
                    ->alignEnd()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('M d, Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('academic_term_id')
                    ->label('Academic Term')
                    ->relationship('academicTerm', 'name'),

                Filter::make('outstanding_debt')
                    ->label('Outstanding balance only')
                    ->query(fn (Builder $query): Builder => $query->whereColumn('total_amount', '>', 'paid_amount')),

                Filter::make('fully_settled')
                    ->label('Fully settled only')
                    ->query(fn (Builder $query): Builder => $query->whereColumn('total_amount', '<=', 'paid_amount')),
            ])
            ->recordActions([
                Action::make('recordPayment')
                    ->label('Record payment')
                    ->icon('heroicon-m-banknotes')
                    ->color('success')
                    ->visible(fn (TuitionAccount $record): bool => $record->remainingAmount() > 0 && Gate::allows('update', $record))
                    ->modalHeading('Record Tuition Payment')
                    ->modalDescription(fn (TuitionAccount $record): string => "Apply a USDC tuition payment towards {$record->student->user->name}'s account for {$record->academicTerm->name}.")
                    ->modalSubmitActionLabel('Confirm payment')
                    ->schema([
                        TextInput::make('amount')
                            ->label('Payment amount (USDC)')
                            ->prefix('USDC')
                            ->required()
                            ->numeric()
                            ->minValue(0.000001)
                            ->maxValue(fn (TuitionAccount $record): float => (float) (new Money($record->remainingAmount(), CurrencyCode::USDC)->decimal()))
                            ->helperText(fn (TuitionAccount $record): string => 'Remaining balance: '.new Money($record->remainingAmount(), CurrencyCode::USDC)->format(2).' USDC')
                            ->placeholder('100.00'),
                    ])
                    ->action(function (array $data, TuitionAccount $record): void {
                        $addUnits = Money::fromDecimal((string) $data['amount'], CurrencyCode::USDC)->toBaseUnits();

                        if ($addUnits > $record->remainingAmount()) {
                            Notification::make()
                                ->title('Amount exceeds remaining balance')
                                ->danger()
                                ->send();

                            return;
                        }

                        $record->update(['paid_amount' => $record->paid_amount + $addUnits]);

                        Notification::make()
                            ->title('Payment recorded')
                            ->body(sprintf('Applied %s USDC to tuition balance.', number_format($addUnits / 1_000_000, 2)))
                            ->success()
                            ->send();
                    }),

                Action::make('viewStudent')
                    ->label('Student')
                    ->icon('heroicon-m-user')
                    ->color('primary')
                    ->url(fn (TuitionAccount $record): string => StudentResource::getUrl(
                        'view',
                        ['record' => $record->student_id],
                        panel: filament()->getCurrentPanel()?->getId(),
                    )),

                ViewAction::make()
                    ->icon(Heroicon::Eye),

                EditAction::make()
                    ->icon(Heroicon::PencilSquare),

                Action::make('arcscan')
                    ->label('ArcScan')
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (TuitionAccount $record): bool => filled($record->student->wallet->address ?? $record->student->payout_address))
                    ->url(function (TuitionAccount $record): ?string {
                        $address = $record->student->wallet->address ?? $record->student->payout_address;

                        if (! $address) {
                            return null;
                        }

                        try {
                            return app(ArcNetworkGateway::class)->addressExplorerUrl($address);
                        } catch (Throwable) {
                            return null;
                        }
                    })
                    ->openUrlInNewTab(),
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
