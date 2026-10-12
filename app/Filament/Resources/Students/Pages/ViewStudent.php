<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\Students\Widgets\StudentWalletWidget;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStudent extends ViewRecord
{
    protected static string $resource = StudentResource::class;

    /**
     * The wallet is a chain reading, not a form field, so it belongs beside the
     * record rather than inside the disabled form above it.
     *
     * @return array<int, class-string>
     */
    #[\Override]
    protected function getFooterWidgets(): array
    {
        return [
            StudentWalletWidget::class,
        ];
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
