<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Resources\Students\StudentResource;
use App\Models\Student;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;

class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->before(function (DeleteAction $action): void {
                    $record = $action->getRecord();
                    $reason = $record instanceof Student ? $record->deletionBlockedReason() : null;

                    if ($reason === null) {
                        return;
                    }

                    Notification::make()
                        ->title('Cannot delete this student')
                        ->body($reason)
                        ->danger()
                        ->send();

                    throw new Halt;
                }),
        ];
    }
}
