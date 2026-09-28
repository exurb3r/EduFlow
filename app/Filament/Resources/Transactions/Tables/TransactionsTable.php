<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transactions\Tables;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Transaction;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

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
                TextColumn::make('settlement')
                    ->label('Settlement')
                    ->badge()
                    ->getStateUsing(fn (Transaction $record): string => self::settlementLabel($record))
                    ->color(fn (Transaction $record): string => self::settlementColor($record))
                    ->tooltip(fn (Transaction $record): string => self::settlementTooltip($record)),
                TextColumn::make('provider_tx_hash')
                    ->label('Arc Tx Hash')
                    ->placeholder('No on-chain transfer')
                    ->copyable()
                    ->limit(16)
                    ->fontFamily('mono')
                    ->tooltip(fn ($record) => $record->provider_tx_hash ?? 'Ledger entry: no on-chain transfer was made.')
                    ->url(fn (Transaction $record): ?string => self::explorerUrl($record))
                    ->openUrlInNewTab(),
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
                Action::make('viewOnArcScan')
                    ->label('View on ArcScan')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (Transaction $record): bool => self::explorerUrl($record) !== null)
                    ->url(fn (Transaction $record): ?string => self::explorerUrl($record))
                    ->openUrlInNewTab(),
                ViewAction::make(),
            ]);
    }

    public static function settlementLabel(Transaction $record): string
    {
        if (($record->metadata['simulated_inbound'] ?? false) === true) {
            return 'Ledger only';
        }

        return ($record->metadata['is_fake'] ?? false) === true ? 'Simulated' : 'On-chain';
    }

    public static function settlementColor(Transaction $record): string
    {
        if (($record->metadata['simulated_inbound'] ?? false) === true) {
            return 'gray';
        }

        return ($record->metadata['is_fake'] ?? false) === true ? 'warning' : 'success';
    }

    public static function settlementTooltip(Transaction $record): string
    {
        $gateway = $record->metadata['gateway'] ?? null;
        $network = $record->metadata['executed_network'] ?? $record->network;

        if (($record->metadata['simulated_inbound'] ?? false) === true) {
            return 'Inbound ledger credit. No USDC moved on '.strtoupper((string) $network).'.';
        }

        if (($record->metadata['is_fake'] ?? false) === true) {
            return 'Executed against the fake Lepton driver. Set LEPTON_DRIVER=circle for real settlement.';
        }

        return 'Settled on '.strtoupper((string) $network).($gateway !== null ? ' via '.$gateway : '').'.';
    }

    /**
     * Prefer the explorer URL captured at execution time, then derive one
     * from the current Arc gateway.
     */
    public static function explorerUrl(Transaction $record): ?string
    {
        if (! $record->provider_tx_hash) {
            return null;
        }

        $captured = $record->metadata['explorer_url'] ?? null;

        if (is_string($captured) && $captured !== '') {
            return $captured;
        }

        try {
            return app(ArcNetworkGateway::class)->explorerUrl($record->provider_tx_hash);
        } catch (Throwable) {
            return null;
        }
    }
}
