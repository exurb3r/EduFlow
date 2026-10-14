<?php

declare(strict_types=1);

namespace App\Filament\Resources\TuitionAccounts\Pages;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\TuitionAccounts\TuitionAccountResource;
use App\Models\TuitionAccount;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditTuitionAccount extends EditRecord
{
    protected static string $resource = TuitionAccountResource::class;

    #[\Override]
    public function getRecord(): TuitionAccount
    {
        /** @var TuitionAccount $record */
        $record = parent::getRecord();

        return $record;
    }

    #[\Override]
    public function getSubheading(): ?string
    {
        $account = $this->getRecord();
        $remaining = new Money($account->remainingAmount(), CurrencyCode::USDC)->format(2);

        return "{$account->student->user->name} ({$account->student->student_number}) • Term: {$account->academicTerm->name} • Balance: {$remaining} USDC";
    }

    #[\Override]
    protected function getSavedNotificationTitle(): ?string
    {
        return 'Tuition account updated';
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
                ->url(fn (): string => StudentResource::getUrl(
                    'view',
                    ['record' => $this->getRecord()->student_id],
                    panel: filament()->getCurrentPanel()?->getId(),
                )),

            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeSave(array $data): array
    {
        TuitionAccountResource::assertUniqueAccount($data, $this->record->getKey());

        return $data;
    }
}
