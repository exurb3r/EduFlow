<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transactions\Pages;

use App\Filament\Resources\Transactions\Tables\TransactionsTable;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Models\Transaction;
use Filament\Actions\Action;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Support\Amounts;

class ViewTransaction extends ViewRecord
{
    protected static string $resource = TransactionResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewOnArcScan')
                ->label('View on ArcScan')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (): bool => TransactionsTable::explorerUrl($this->getRecord()) !== null)
                ->url(fn (): ?string => TransactionsTable::explorerUrl($this->getRecord()))
                ->openUrlInNewTab(),
        ];
    }

    #[\Override]
    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Settlement')
                ->columns(3)
                ->schema([
                    TextEntry::make('type')->badge(),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('amount')
                        ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' USDC')
                        ->weight('bold'),
                    TextEntry::make('network')
                        ->badge()
                        ->formatStateUsing(fn ($state): string => strtoupper((string) $state)),
                    TextEntry::make('settlement')
                        ->label('Provenance')
                        ->badge()
                        ->state(fn (Transaction $record): string => TransactionsTable::settlementLabel($record))
                        ->color(fn (Transaction $record): string => TransactionsTable::settlementColor($record))
                        ->tooltip(fn (Transaction $record): string => TransactionsTable::settlementTooltip($record)),
                    TextEntry::make('executed_at')->dateTime(),
                ]),
            Section::make('On-chain receipt')
                ->columns(2)
                ->visible(fn (): bool => $this->getRecord()->provider_tx_hash !== null)
                ->schema([
                    TextEntry::make('provider_tx_hash')
                        ->label('Tx hash')
                        ->fontFamily('mono')
                        ->copyable()
                        ->url(fn (Transaction $record): ?string => TransactionsTable::explorerUrl($record))
                        ->openUrlInNewTab(),
                    TextEntry::make('chain')
                        ->label('Chain')
                        ->state(function (): string {
                            $record = $this->getRecord();

                            try {
                                $arc = app(ArcNetworkGateway::class);

                                return $arc->chainCode().' (id '.$arc->chainId().')';
                            } catch (Throwable) {
                                return strtoupper((string) $record->network);
                            }
                        }),
                    TextEntry::make('block')
                        ->label('Current block')
                        ->state(function (): string {
                            try {
                                $block = app(ArcNetworkGateway::class)->blockNumber();

                                return is_string($block) ? number_format((int) Amounts::fromHexQuantity($block, 0)) : 'unavailable';
                            } catch (Throwable) {
                                return 'unavailable';
                            }
                        }),
                    TextEntry::make('amount_base_units')
                        ->label('Amount (base units)')
                        ->state(fn (Transaction $record): string => (string) ($record->metadata['amount_base_units'] ?? 'n/a'))
                        ->fontFamily('mono'),
                ]),
            Section::make('Lepton metadata')
                ->collapsible()
                ->schema([
                    KeyValueEntry::make('metadata')
                        ->label(false)
                        ->state(fn (Transaction $record): array => is_array($record->metadata) ? $record->metadata : []),
                ]),
        ]);
    }
}
