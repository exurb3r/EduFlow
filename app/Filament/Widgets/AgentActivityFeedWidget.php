<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\AgentDecisions\AgentDecisionResource;
use App\Filament\Support\LinkedHeading;
use App\Models\AgentDecision;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class AgentActivityFeedWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    /**
     * Legacy student-assistance decision log. These rows come from the earlier
     * demo path, not the vendor payment chain in the finance panel: the
     * amounts are legacy two-decimal floats, and an "executed" outcome here is
     * a ledger write rather than verified Arc settlement.
     */
    private const string LEGACY_NOTICE = 'Legacy student-assistance decision log. These rows come from the earlier demo path, not the vendor payment chain in the finance panel: the amounts are legacy two-decimal floats, and an "executed" outcome here is a ledger write rather than verified Arc settlement.';

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->heading(LinkedHeading::make('Legacy assistance decision log', AgentDecisionResource::getUrl('index')))
            ->description(self::LEGACY_NOTICE)
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
                    ->label('Verdict')
                    ->badge(),
                TextColumn::make('requested_amount')
                    ->label('Requested')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' USDC')
                    ->weight('bold'),
                TextColumn::make('approved_amount')
                    ->label('Approved')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' USDC'),
                TextColumn::make('policy_checked')
                    ->label('Policy checked')
                    ->badge()
                    ->color('info'),
                TextColumn::make('reasoning_summary')
                    ->label('Recorded summary')
                    ->limit(65)
                    ->tooltip(fn ($record) => $record->reasoning_summary),
                TextColumn::make('status')
                    ->label('Ledger outcome')
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
