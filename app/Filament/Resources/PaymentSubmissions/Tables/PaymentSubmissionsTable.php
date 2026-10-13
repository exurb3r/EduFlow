<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentSubmissions\Tables;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
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
            ->heading('Submission history')
            ->description('Read-only provider attempts. Accepted means the provider acknowledged a request; only verified settlement proves payment. Investigate unresolved attempts before any resend.')
            ->poll('10s')
            ->stackedOnMobile()
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
                        'submitted' => 'hash awaiting verification',
                        'completed' => 'verified settled',
                        'unknown' => 'unresolved',
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('stage')
                    ->label('Workflow stage')
                    ->wrap()
                    ->placeholder('not yet dispatched')
                    ->toggleable(),

                TextColumn::make('intent.invoice.reference')
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
                    ->label('Provider transaction ID')
                    ->copyable()
                    ->limit(14)
                    ->placeholder('not recorded — outcome may be unknown')
                    // A handle is not a hash. Labelling it as one is how an
                    // in-flight payment gets read as settled.
                    ->description('Provider acknowledgement, not settlement')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('result.provider_reference')
                    ->label('Transaction hash')
                    ->copyable()
                    ->limit(12)
                    ->placeholder('not yet published')
                    ->description('A published hash still requires on-chain verification')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('attempts')
                    ->label('Attempts')
                    ->alignment('right')
                    ->badge()
                    ->color(fn ($state): string => (int) $state > 1 ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_error')
                    ->label('Provider detail')
                    ->wrap()
                    ->limit(120)
                    ->placeholder('no detail recorded')
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
                        'submitted' => 'hash awaiting verification',
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
                        'open' => 'queued, running or paused',
                        'in_flight' => 'accepted or awaiting verification',
                        'terminal_unresolved' => 'unresolved, blocked or failed',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'open' => $query->whereIn('state', ['queued', 'running', 'paused']),
                        'in_flight' => $query->whereIn('state', PaymentSubmissionOutbox::IN_FLIGHT_STATES),
                        'terminal_unresolved' => $query->whereIn('state', ['unknown', 'blocked', 'failed']),
                        default => $query,
                    }),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                ViewAction::make()->label('Inspect attempt'),
            ]);
    }

    private static function amount(PaymentSubmissionOutbox $record): string
    {
        return Money::formatExact($record->snapshot['amount_base_units'] ?? null, CurrencyCode::USDC->value);
    }
}
