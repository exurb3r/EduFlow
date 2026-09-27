<?php

declare(strict_types=1);

namespace App\Filament\Resources\AgentDecisions\Pages;

use App\Filament\Resources\AgentDecisions\AgentDecisionResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAgentDecision extends ViewRecord
{
    protected static string $resource = AgentDecisionResource::class;
}
