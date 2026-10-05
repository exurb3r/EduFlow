<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\AgentDecision;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class AgentActivityFeedWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->heading('EduFlow AI — Autonomous Decision Activity Feed')
            ->description('Real-time audit log of financial observations, deterministic rule checks, and on-chain Arc executions.')
            ->query(AgentDecision::query()->latest())
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
                    ->label('AI Decision')
                    ->badge(),
                TextColumn::make('requested_amount')
                    ->label('Requested')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' USDC')
                    ->weight('bold'),
                TextColumn::make('approved_amount')
                    ->label('Approved')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' USDC'),
                TextColumn::make('policy_checked')
                    ->label('Policy Checked')
                    ->badge()
                    ->color('info'),
                TextColumn::make('reasoning_summary')
                    ->label('Reasoning & Verification')
                    ->limit(65)
                    ->tooltip(fn ($record) => $record->reasoning_summary),
                TextColumn::make('status')
                    ->label('Outcome')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'executed' => 'success',
                        'escalated' => 'danger',
                        'held' => 'warning',
                        'rejected' => 'gray',
                        default => 'info',
                    }),
            ]);
    }
}
