<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistanceRequests\Pages;

use App\Filament\Resources\AssistanceRequests\AssistanceRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAssistanceRequest extends ViewRecord
{
    protected static string $resource = AssistanceRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
