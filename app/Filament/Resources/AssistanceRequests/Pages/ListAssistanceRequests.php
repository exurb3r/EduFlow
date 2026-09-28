<?php

namespace App\Filament\Resources\AssistanceRequests\Pages;

use App\Filament\Resources\AssistanceRequests\AssistanceRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssistanceRequests extends ListRecords
{
    protected static string $resource = AssistanceRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
