<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\RelationManagers;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Student;
use App\Models\StudentBill;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class StudentBillsRelationManager extends RelationManager
{
    protected static string $relationship = 'bills';

    protected static ?string $title = 'Non-Tuition Charges & Bills';

    protected static string|BackedEnum|null $icon = Heroicon::ReceiptPercent;

    #[\Override]
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Student $ownerRecord */
        $count = $ownerRecord->bills()->count();

        return $count > 0 ? (string) $count : null;
    }

    #[\Override]
    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'gray';
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No non-tuition charges on file')
            ->emptyStateDescription('Laboratory, examination, and incidental fees will appear here.')
            ->emptyStateIcon(Heroicon::ReceiptPercent)
            ->columns([
                TextColumn::make('description')
                    ->label('Charge Description')
                    ->weight('bold')
                    ->searchable(),

                TextColumn::make('category')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucfirst(str_replace('_', ' ', is_object($state) ? $state->value : (string) $state)))
                    ->sortable(),

                TextColumn::make('amount_base_units')
                    ->label('Total (USDC)')
                    ->formatStateUsing(
                        fn ($state): string => new Money((int) $state, CurrencyCode::USDC)->format(2).' USDC',
                    )
                    ->weight('bold')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('paid_base_units')
                    ->label('Paid (USDC)')
                    ->formatStateUsing(
                        fn ($state): string => new Money((int) $state, CurrencyCode::USDC)->format(2).' USDC',
                    )
                    ->color('success')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('remaining')
                    ->label('Remaining (USDC)')
                    ->state(fn (StudentBill $record): string => new Money(max(0, $record->amount_base_units - $record->paid_base_units), CurrencyCode::USDC)->format(2).' USDC')
                    ->weight('bold')
                    ->color(fn (StudentBill $record): string => $record->amount_base_units <= $record->paid_base_units ? 'success' : 'danger')
                    ->alignEnd(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        StudentBill::STATUS_PAID => 'success',
                        StudentBill::STATUS_PARTIALLY_PAID => 'warning',
                        StudentBill::STATUS_OPEN => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('M d, Y')
                    ->placeholder('None')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema([
                        Section::make('Bill Details')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('description')
                                    ->weight('bold'),

                                TextEntry::make('category')
                                    ->badge(),

                                TextEntry::make('amount_base_units')
                                    ->label('Total Charge')
                                    ->state(fn (StudentBill $record): string => new Money($record->amount_base_units, CurrencyCode::USDC)->format(2).' USDC')
                                    ->weight('bold'),

                                TextEntry::make('paid_base_units')
                                    ->label('Amount Paid')
                                    ->state(fn (StudentBill $record): string => new Money($record->paid_base_units, CurrencyCode::USDC)->format(2).' USDC')
                                    ->color('success')
                                    ->weight('bold'),

                                TextEntry::make('status')
                                    ->badge(),

                                TextEntry::make('due_date')
                                    ->label('Due Date')
                                    ->date('M d, Y')
                                    ->placeholder('None'),
                            ]),
                    ]),
            ]);
    }
}
