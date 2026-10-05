<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentDecisions\Tables;

use App\Enums\AgentDecisionType;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AgentDecisionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Timestamp')
                    ->dateTime('M d, H:i:s')
                    ->sortable(),
                TextColumn::make('action_type')
                    ->label('Action')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('decision')
                    ->label('Decision')
                    ->badge(),
                TextColumn::make('requested_amount')
                    ->label('Requested')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' USDC')
                    ->weight('bold'),
                TextColumn::make('approved_amount')
                    ->label('Approved')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' USDC'),
                TextColumn::make('policy_checked')
                    ->label('Policy Trigger')
                    ->badge()
                    ->color('info'),
                TextColumn::make('reasoning_summary')
                    ->label('AI Reasoning & Explanation')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->reasoning_summary),
                TextColumn::make('status')
                    ->label('Execution Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'executed' => 'success',
                        'escalated' => 'danger',
                        'held' => 'warning',
                        'rejected' => 'gray',
                        default => 'info',
                    }),
            ])
            ->filters([
                SelectFilter::make('decision')
                    ->options(AgentDecisionType::class),
                SelectFilter::make('status')
                    ->options([
                        'executed' => 'Executed',
                        'escalated' => 'Escalated',
                        'held' => 'Held',
                        'rejected' => 'Rejected',
                        'pending' => 'Pending',
                    ]),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
