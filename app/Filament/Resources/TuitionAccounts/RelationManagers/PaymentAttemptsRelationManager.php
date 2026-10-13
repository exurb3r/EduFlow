<?php

declare(strict_types=1);

namespace App\Filament\Resources\TuitionAccounts\RelationManagers;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Enums\StudentTuitionPaymentState;
use App\Models\StudentTuitionPaymentAttempt;
use App\Models\TuitionAccount;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PaymentAttemptsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentAttempts';

    protected static ?string $title = 'Payment Attempts & Network State';

    protected static string|BackedEnum|null $icon = Heroicon::ArrowPath;

    #[\Override]
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var TuitionAccount $ownerRecord */
        $count = $ownerRecord->paymentAttempts()->count();

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
            ->recordTitleAttribute('request_key')
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No payment attempts recorded')
            ->emptyStateDescription('Real-time payment attempts, network responses, and settlement verdicts will appear here.')
            ->emptyStateIcon(Heroicon::ArrowPath)
            ->columns([
                TextColumn::make('created_at')
                    ->label('Attempted at')
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

                TextColumn::make('state')
                    ->label('Lifecycle state')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'verified' => 'success',
                        'failed', 'unknown' => 'danger',
                        'prepared', 'submitting', 'accepted', 'awaiting_verification' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(
                        fn (string $state): string => StudentTuitionPaymentState::tryFrom($state)?->label() ?? ucfirst($state),
                    )
                    ->sortable(),

                TextColumn::make('stage')
                    ->label('Pipeline stage')
                    ->placeholder('Not dispatched')
                    ->toggleable(),

                TextColumn::make('settlement_verdict')
                    ->label('Settlement verdict')
                    ->badge()
                    ->placeholder('Pending')
                    ->toggleable(),

                TextColumn::make('last_error')
                    ->label('Last error')
                    ->color('danger')
                    ->limit(25)
                    ->tooltip(fn (StudentTuitionPaymentAttempt $record): ?string => $record->last_error)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('request_key')
                    ->label('Request key')
                    ->fontFamily('mono')
                    ->copyable()
                    ->limit(12)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema([
                        Section::make('Attempt Overview')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('created_at')
                                    ->label('Initiated At')
                                    ->dateTime('M d, Y H:i:s'),

                                TextEntry::make('amount_base_units')
                                    ->label('Attempt Amount')
                                    ->state(fn (StudentTuitionPaymentAttempt $record): string => new Money($record->amount_base_units, CurrencyCode::USDC)->format(2).' USDC')
                                    ->weight('bold'),

                                TextEntry::make('state')
                                    ->label('State')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'verified' => 'success',
                                        'failed', 'unknown' => 'danger',
                                        'prepared', 'submitting', 'accepted', 'awaiting_verification' => 'warning',
                                        default => 'gray',
                                    })
                                    ->formatStateUsing(fn (string $state): string => StudentTuitionPaymentState::tryFrom($state)?->label() ?? ucfirst($state)),

                                TextEntry::make('stage')
                                    ->label('Stage')
                                    ->placeholder('Not recorded'),

                                TextEntry::make('settlement_verdict')
                                    ->label('Verdict')
                                    ->badge()
                                    ->placeholder('None'),

                                TextEntry::make('settlement_reason')
                                    ->label('Verdict Reason')
                                    ->placeholder('None'),
                            ]),

                        Section::make('Network & Provider Details')
                            ->columns(1)
                            ->schema([
                                TextEntry::make('request_key')
                                    ->label('Request Key')
                                    ->fontFamily('mono')
                                    ->copyable(),

                                TextEntry::make('provider_handle')
                                    ->label('Provider Handle')
                                    ->fontFamily('mono')
                                    ->copyable()
                                    ->placeholder('Not assigned'),

                                TextEntry::make('provider_reference')
                                    ->label('Provider Reference')
                                    ->fontFamily('mono')
                                    ->copyable()
                                    ->placeholder('Not assigned'),

                                TextEntry::make('last_error')
                                    ->label('Last Error')
                                    ->color('danger')
                                    ->placeholder('No errors reported'),
                            ]),
                    ]),
            ]);
    }
}
