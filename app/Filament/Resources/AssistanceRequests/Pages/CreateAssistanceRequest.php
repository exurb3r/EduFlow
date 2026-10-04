<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistanceRequests\Pages;

use App\Filament\Resources\AssistanceRequests\AssistanceRequestResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAssistanceRequest extends CreateRecord
{
    protected static string $resource = AssistanceRequestResource::class;
}
