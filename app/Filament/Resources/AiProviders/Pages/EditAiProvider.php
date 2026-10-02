<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\AiProvider;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAiProvider extends EditRecord
{
    protected static string $resource = AiProviderResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * A blank key field means "keep what is stored", so the record is only
     * updated when a new one was actually typed.
     */
    #[\Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }

        if ($data['is_default'] ?? false) {
            AiProvider::query()
                ->whereKeyNot($this->record->getKey())
                ->update(['is_default' => false]);
        }

        return $data;
    }

    /**
     * Never hand the stored key to the form.
     */
    #[\Override]
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['api_key']);

        return $data;
    }
}
