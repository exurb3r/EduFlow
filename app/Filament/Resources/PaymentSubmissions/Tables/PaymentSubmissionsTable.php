<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSubmissions\Tables;

use App\Models\PaymentSubmissionOutbox;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the lane is doing, at a glance.
 *
 * Every column here answers a question an operator actually asks. The state says
 * whether the rail has taken the payment; the stage says how far it got; the
 * handle is the rail's own identifier and is only meaningful while the entry is
 * in flight; and the provider's words are shown verbatim, because "the rail
 * refused this" and "we could not reach the rail" are different problems.
 */
class PaymentSubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('state')
                    ->label('State')
                    ->badge()
                    // The colour is the summary, not decoration: an entry the
                    // rail already owns must never look the same as one sitting
                    // in a queue.
                    ->color(fn (string $state): string => match ($state) {
                        'queued', 'running' => 'warning',
                        'accepted', 'submitted' => 'info',
                        'completed' => 'success',
                        'unknown' => 'danger',
                        'blocked', 'failed' => 'danger',
                        'paused' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'accepted' => 'accepted by the rail',
                        'submitted' => 'reference held',
                        'completed' => 'verified settled',
                        'unknown' => 'unresolved',
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('stage')
                    ->label('Stage')
                    ->wrap()
                    ->placeholder('not yet dispatched'),

                TextColumn::make('invoice.reference')
                    ->label('Bill')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('snapshot.amount_base_units')
                    ->label('Amount')
                    ->state(fn (PaymentSubmissionOutbox $record): string => self::amount($record))
                    ->alignment('right'),

                TextColumn::make('snapshot.recipient_address')
                    ->label('Recipient')
                    ->copyable()
                    ->limit(12)
                    ->tooltip(fn (PaymentSubmissionOutbox $record): ?string => $record->snapshot['recipient_address'] ?? null),

                TextColumn::make('provider_handle')
                    ->label('Rail handle')
                    ->copyable()
                    ->limit(14)
                    ->placeholder('none — no transfer in flight')
                    // A handle is not a hash. Labelling it as one is how an
                    // in-flight payment gets read as settled.
                    ->description("the rail's own transaction id, not an on-chain reference"),

                TextColumn::make('result.provider_reference')
                    ->label('On-chain reference')
                    ->copyable()
                    ->limit(12)
                    ->placeholder('not yet published')
                    ->description('exists only once the transfer completed'),

                TextColumn::make('attempts')
                    ->label('Tries')
                    ->alignment('right')
                    ->badge()
                    ->color(fn ($state): string => (int) $state > 1 ? 'warning' : 'gray'),

                TextColumn::make('last_error')
                    ->label('What the rail said')
                    ->wrap()
                    ->limit(120)
                    ->placeholder('nothing to report')
                    // Shown verbatim. Rewriting or suppressing the provider's
                    // own wording is what makes a refusal indistinguishable
                    // from an outage.
                    ->tooltip(fn (PaymentSubmissionOutbox $record): ?string => $record->last_error),

                TextColumn::make('updated_at')
                    ->label('Last activity')
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->options(fn (): array => [
                        'queued' => 'queued',
                        'running' => 'running',
                        'accepted' => 'accepted by the rail',
                        'submitted' => 'reference held',
                        'unknown' => 'unresolved',
                        'completed' => 'verified settled',
                        'blocked' => 'blocked',
                        'failed' => 'failed',
                        'paused' => 'paused',
                    ])
                    ->multiple(),

                SelectFilter::make('needs attention')
                    ->label('Needs attention')
                    ->options([
                        'open' => 'still owed work',
                        'in_flight' => 'the rail already owns it',
                        'terminal_unresolved' => 'finished without paying',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'open' => $query->whereIn('state', PaymentSubmissionOutbox::DISPATCHABLE_STATES),
                            'in_flight' => $query->whereIn('state', PaymentSubmissionOutbox::IN_FLIGHT_STATES),
                            'terminal_unresolved' => $query->whereIn('state', ['unknown', 'blocked', 'failed']),
                            default => $query,
                        };
                    }),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    private static function amount(PaymentSubmissionOutbox $record): string
    {
        $base = $record->snapshot['amount_base_units'] ?? null;

        if (! is_numeric($base)) {
            return 'unknown';
        }

        return number_format(((float) $base) / 1_000000, 2).' USDC';
    }
}
