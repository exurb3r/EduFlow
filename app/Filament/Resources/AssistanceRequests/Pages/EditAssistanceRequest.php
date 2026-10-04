<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistanceRequests\Pages;

use App\Filament\Resources\AssistanceRequests\AssistanceRequestResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAssistanceRequest extends EditRecord
{
    protected static string $resource = AssistanceRequestResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
