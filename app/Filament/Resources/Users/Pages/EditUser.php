<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    #[\Override]
    public function getRecord(): User
    {
        /** @var User $record */
        $record = parent::getRecord();

        return $record;
    }

    #[\Override]
    public function getSubheading(): ?string
    {
        $user = $this->getRecord();

        return "{$user->email} (#{$user->id})";
    }

    #[\Override]
    protected function getSavedNotificationTitle(): ?string
    {
        return 'User account updated';
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->icon(Heroicon::Eye),

            Action::make('viewStudent')
                ->label('Student Profile')
                ->icon(Heroicon::AcademicCap)
                ->color('primary')
                ->visible(fn (): bool => $this->getRecord()->student !== null)
                ->url(fn (): string => StudentResource::getUrl('view', ['record' => $this->getRecord()->student->id])),

            Action::make('impersonate')
                ->label('Impersonate')
                ->icon('heroicon-o-user-plus')
                ->color('warning')
                ->url(fn (): string => route('impersonate', $this->getRecord()->id))
                ->openUrlInNewTab()
                ->visible(fn (): bool => ($u = Auth::user()) instanceof User && $u->hasRole('admin') && $this->getRecord()->canBeImpersonated()),

            DeleteAction::make()
                ->before(function (DeleteAction $action): void {
                    $user = $action->getRecord();

                    if ($user instanceof User && $user->id === Auth::id()) {
                        Notification::make()
                            ->title('Cannot delete yourself')
                            ->body('Self-deletion is blocked to prevent accidental administrative lockout.')
                            ->danger()
                            ->send();

                        throw new Halt;
                    }

                    if ($user instanceof User && $user->student()->exists() && $user->student->deletionBlockedReason() !== null) {
                        Notification::make()
                            ->title('Cannot delete student user')
                            ->body('This user has an active student profile with financial records on file.')
                            ->danger()
                            ->send();

                        throw new Halt;
                    }
                }),
        ];
    }
}
