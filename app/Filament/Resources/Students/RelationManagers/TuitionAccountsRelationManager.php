<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\RelationManagers;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Filament\Resources\TuitionAccounts\TuitionAccountResource;
use App\Models\Student;
use App\Models\TuitionAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TuitionAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'tuitionAccounts';

    protected static ?string $title = 'Tuition Accounts';

    protected static string|BackedEnum|null $icon = Heroicon::Banknotes;

    #[\Override]
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Student $ownerRecord */
        $count = $ownerRecord->tuitionAccounts()->count();

        return $count > 0 ? (string) $count : null;
    }

    #[\Override]
    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'primary';
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('No tuition accounts on file')
            ->emptyStateDescription('Create a tuition account for this student for an academic term.')
            ->emptyStateIcon(Heroicon::Banknotes)
            ->columns([
                TextColumn::make('academicTerm.name')
                    ->label('Academic Term')
                    ->badge()
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
                    ->label('Amount Paid')
                    ->formatStateUsing(
                        fn ($state): string => new Money((int) $state, CurrencyCode::USDC)->format(2).' USDC',
                    )
                    ->color('success')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('remaining')
                    ->label('Remaining Balance')
                    ->getStateUsing(
                        fn (TuitionAccount $record): string => new Money($record->remainingAmount(), CurrencyCode::USDC)->format(2).' USDC',
                    )
                    ->weight('bold')
                    ->color(fn (TuitionAccount $record): string => $record->remainingAmount() === 0 ? 'success' : 'danger')
                    ->alignEnd(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (TuitionAccount $record): string => $record->statusLabel())
                    ->color(fn (TuitionAccount $record): string => $record->statusColor()),

                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime('M d, Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('New tuition account')
                    ->modalHeading('Create Tuition Account for Student')
                    ->schema([
                        Select::make('academic_term_id')
                            ->label('Academic Term')
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
                            ->dehydrateStateUsing(
                                fn ($state): int => Money::fromDecimal((string) $state, CurrencyCode::USDC)->toBaseUnits(),
                            ),

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
                            ->rules(['regex:/^\d+(\.\d{1,6})?$/'])
                            ->dehydrateStateUsing(
                                fn ($state): int => Money::fromDecimal((string) $state, CurrencyCode::USDC)->toBaseUnits(),
                            ),
                    ]),
            ])
            ->recordActions([
                Action::make('viewAccount')
                    ->label('View account')
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->color('primary')
                    ->url(fn (TuitionAccount $record): string => TuitionAccountResource::getUrl(
                        'view',
                        ['record' => $record->id],
                        panel: filament()->getCurrentPanel()?->getId(),
                    )),

                EditAction::make()
                    ->schema([
                        TextInput::make('total_amount')
                            ->label('Total tuition (USDC)')
                            ->prefix('USDC')
                            ->required()
                            ->numeric()
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
                            ->required()
                            ->numeric()
                            ->formatStateUsing(
                                fn ($state): ?string => $state === null
                                    ? null
                                    : new Money((int) $state, CurrencyCode::USDC)->decimal(),
                            )
                            ->dehydrateStateUsing(
                                fn ($state): int => Money::fromDecimal((string) $state, CurrencyCode::USDC)->toBaseUnits(),
                            ),
                    ]),
            ]);
    }
}
