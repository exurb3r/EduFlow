<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transactions\Tables;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Executed At')
                    ->dateTime('M d, Y H:i:s')
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).' USDC')
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('network')
                    ->label('Network')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn ($state) => strtoupper((string) $state)),
                TextColumn::make('provider_tx_hash')
                    ->label('Arc Tx Hash')
                    ->copyable()
                    ->limit(16)
                    ->fontFamily('mono')
                    ->tooltip(fn ($record) => $record->provider_tx_hash),
                TextColumn::make('recipient_address')
                    ->label('Recipient Address')
                    ->copyable()
                    ->limit(16)
                    ->fontFamily('mono')
                    ->tooltip(fn ($record) => $record->recipient_address),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(TransactionType::class),
                SelectFilter::make('status')
                    ->options(TransactionStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
