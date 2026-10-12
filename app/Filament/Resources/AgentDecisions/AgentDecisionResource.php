<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentDecisions;

use App\Filament\Resources\AgentDecisions\Pages\ListAgentDecisions;
use App\Filament\Resources\AgentDecisions\Pages\ViewAgentDecision;
use App\Filament\Resources\AgentDecisions\Tables\AgentDecisionsTable;
use App\Models\AgentDecision;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AgentDecisionResource extends Resource
{
    protected static ?string $model = AgentDecision::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Sparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Finance records';

    protected static ?string $navigationLabel = 'AI Decision Log';

    protected static ?int $navigationSort = 5;

    #[\Override]
    public static function table(Table $table): Table
    {
        return AgentDecisionsTable::configure($table);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListAgentDecisions::route('/'),
            'view' => ViewAgentDecision::route('/{record}'),
        ];
    }
}
