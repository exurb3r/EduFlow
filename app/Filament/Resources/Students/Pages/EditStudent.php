<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\Pages;

use App\Filament\Resources\Students\StudentResource;
use App\Models\Student;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    #[\Override]
    public function getRecord(): Student
    {
        /** @var Student $record */
        $record = parent::getRecord();

        return $record;
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            Action::make('viewOnArcScan')
                ->label('Student ArcScan')
                ->icon(Heroicon::ArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (): bool => filled($this->getRecord()->wallet->address ?? $this->getRecord()->payout_address))
                ->url(function (): ?string {
                    $address = $this->getRecord()->wallet->address ?? $this->getRecord()->payout_address;

                    if (! $address) {
                        return null;
                    }

                    try {
                        return app(ArcNetworkGateway::class)->addressExplorerUrl($address);
                    } catch (\Throwable) {
                        return null;
                    }
                })
                ->openUrlInNewTab(),
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
