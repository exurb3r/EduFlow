<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\AiProvider;
use Filament\Resources\Pages\CreateRecord;

class CreateAiProvider extends CreateRecord
{
    protected static string $resource = AiProviderResource::class;

    /**
     * Only one provider can be the default.
     *
     * When the operator does not tick the box, this record becomes the default
     * only if there is not already one; otherwise the existing default stands.
     * When they do tick it, every other default is cleared first.
     */
    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if ($data['is_default'] ?? false) {
            AiProvider::clearDefault();
        } elseif (! AiProvider::query()->where('is_default', true)->exists()) {
            // First provider: make it the default so the app has something to call.
            $data['is_default'] = true;
        }

        return $data;
    }
}
