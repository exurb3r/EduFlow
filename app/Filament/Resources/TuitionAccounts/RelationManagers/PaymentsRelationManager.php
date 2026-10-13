<?php

declare(strict_types=1);

namespace App\Filament\Resources\TuitionAccounts\RelationManagers;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\StudentTuitionPayment;
use App\Models\TuitionAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Settled Tuition Payments';

    protected static string|BackedEnum|null $icon = Heroicon::CreditCard;

    #[\Override]
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var TuitionAccount $ownerRecord */
        $count = $ownerRecord->payments()->count();

        return $count > 0 ? (string) $count : null;
    }

    #[\Override]
    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'success';
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('tx_hash')
            ->defaultSort('executed_at', 'desc')
            ->emptyStateHeading('No payments recorded yet')
            ->emptyStateDescription('Payments settled through Arc Network or recorded by staff will appear here.')
            ->emptyStateIcon(Heroicon::Banknotes)
            ->columns([
                TextColumn::make('executed_at')
                    ->label('Settled at')
                    ->dateTime('M d, Y H:i')
                    ->sortable(),

                TextColumn::make('amount_base_units')
                    ->label('Amount')
                    ->formatStateUsing(
                        fn ($state): string => new Money((int) $state, CurrencyCode::USDC)->format(2).' USDC',
                    )
                    ->weight('bold')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('applied_base_units')
                    ->label('Applied')
                    ->formatStateUsing(
                        fn ($state): string => new Money((int) $state, CurrencyCode::USDC)->format(2).' USDC',
                    )
                    ->color('success')
                    ->sortable()
                    ->alignEnd(),

                TextColumn::make('network_fee')
                    ->label('Network Fee')
                    ->formatStateUsing(
                        fn ($state): string => $state !== null ? $state.' USDC' : '—',
                    )
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->alignEnd(),

                TextColumn::make('chain')
                    ->label('Chain')
                    ->badge()
                    ->sortable(),

                IconColumn::make('tx_verified_on_chain')
                    ->label('Verified')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('tx_hash')
                    ->label('Transaction Hash')
                    ->fontFamily('mono')
                    ->copyable()
                    ->limit(16)
                    ->tooltip(fn (StudentTuitionPayment $record): string => $record->tx_hash)
                    ->url(
                        fn (StudentTuitionPayment $record): ?string => $record->tx_hash
                            ? app(ArcNetworkGateway::class)->explorerUrl($record->tx_hash)
                            : null,
                    )
                    ->openUrlInNewTab(),

                TextColumn::make('payer_address')
                    ->label('Payer Wallet')
                    ->fontFamily('mono')
                    ->copyable()
                    ->limit(16)
                    ->tooltip(fn (StudentTuitionPayment $record): string => $record->payer_address)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema([
                        Section::make('Settlement Details')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('executed_at')
                                    ->label('Executed At')
                                    ->dateTime('M d, Y H:i:s'),

                                TextEntry::make('chain')
                                    ->label('Network Chain')
                                    ->badge(),

                                TextEntry::make('amount_base_units')
                                    ->label('Total Payment')
                                    ->state(fn (StudentTuitionPayment $record): string => new Money($record->amount_base_units, CurrencyCode::USDC)->format(2).' USDC')
                                    ->weight('bold'),

                                TextEntry::make('applied_base_units')
                                    ->label('Applied to Tuition')
                                    ->state(fn (StudentTuitionPayment $record): string => new Money($record->applied_base_units, CurrencyCode::USDC)->format(2).' USDC')
                                    ->color('success')
                                    ->weight('bold'),

                                TextEntry::make('network_fee')
                                    ->label('Network Fee')
                                    ->state(fn (StudentTuitionPayment $record): string => $record->network_fee ? $record->network_fee.' USDC' : '0.00 USDC'),

                                IconEntry::make('tx_verified_on_chain')
                                    ->label('On-Chain Verified')
                                    ->boolean(),
                            ]),

                        Section::make('On-Chain Proof & Identifiers')
                            ->columns(1)
                            ->schema([
                                TextEntry::make('tx_hash')
                                    ->label('Transaction Hash')
                                    ->fontFamily('mono')
                                    ->copyable()
                                    ->url(
                                        fn (StudentTuitionPayment $record): ?string => $record->tx_hash
                                            ? app(ArcNetworkGateway::class)->explorerUrl($record->tx_hash)
                                            : null,
                                    )
                                    ->openUrlInNewTab(),

                                TextEntry::make('payer_address')
                                    ->label('Payer Address')
                                    ->fontFamily('mono')
                                    ->copyable(),

                                TextEntry::make('recipient_address')
                                    ->label('Recipient (Institution Treasury)')
                                    ->fontFamily('mono')
                                    ->copyable(),

                                TextEntry::make('request_key')
                                    ->label('Request Key')
                                    ->fontFamily('mono')
                                    ->copyable()
                                    ->placeholder('N/A'),
                            ]),
                    ]),

                Action::make('viewOnArcScan')
                    ->label('ArcScan')
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->color('gray')
                    ->visible(fn (StudentTuitionPayment $record): bool => filled($record->tx_hash))
                    ->url(
                        fn (StudentTuitionPayment $record): ?string => $record->tx_hash
                            ? app(ArcNetworkGateway::class)->explorerUrl($record->tx_hash)
                            : null,
                    )
                    ->openUrlInNewTab(),
            ]);
    }
}
