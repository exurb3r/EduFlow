<?php

declare(strict_types=1);

namespace App\Filament\Resources\TuitionAccounts\Pages;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\TuitionAccounts\TuitionAccountResource;
use App\Filament\Resources\TuitionAccounts\Widgets\TuitionAccountOverviewWidget;
use App\Models\TuitionAccount;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class ViewTuitionAccount extends ViewRecord
{
    protected static string $resource = TuitionAccountResource::class;

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            TuitionAccountOverviewWidget::class,
        ];
    }

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

        return "{$account->student->user->name} ({$account->student->student_number}) • Term: {$account->academicTerm->name} • Remaining: {$remaining} USDC";
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordPayment')
                ->label('Record payment')
                ->icon('heroicon-m-banknotes')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->remainingAmount() > 0 && Gate::allows('update', $this->getRecord()))
                ->modalHeading('Record Tuition Payment')
                ->modalDescription('Record a payment towards this student tuition account in USDC.')
                ->modalSubmitActionLabel('Confirm payment')
                ->schema([
                    TextInput::make('amount')
                        ->label('Payment amount (USDC)')
                        ->prefix('USDC')
                        ->required()
                        ->numeric()
                        ->minValue(0.000001)
                        ->maxValue(fn (): float => (float) (new Money($this->getRecord()->remainingAmount(), CurrencyCode::USDC)->decimal()))
                        ->helperText(fn (): string => 'Remaining balance: '.new Money($this->getRecord()->remainingAmount(), CurrencyCode::USDC)->format(2).' USDC')
                        ->placeholder('100.00'),
                ])
                ->action(function (array $data): void {
                    $account = $this->getRecord();
                    $addUnits = Money::fromDecimal((string) $data['amount'], CurrencyCode::USDC)->toBaseUnits();

                    if ($addUnits > $account->remainingAmount()) {
                        Notification::make()
                            ->title('Amount exceeds remaining balance')
                            ->danger()
                            ->send();

                        return;
                    }

                    $account->update(['paid_amount' => $account->paid_amount + $addUnits]);

                    Notification::make()
                        ->title('Payment recorded')
                        ->body(sprintf('Applied %s USDC to tuition balance.', number_format($addUnits / 1_000_000, 2)))
                        ->success()
                        ->send();
                }),

            Action::make('viewStudent')
                ->label('Student profile')
                ->icon(Heroicon::AcademicCap)
                ->color('primary')
                ->url(fn (): string => StudentResource::getUrl(
                    'view',
                    ['record' => $this->getRecord()->student_id],
                    panel: filament()->getCurrentPanel()?->getId(),
                )),

            Action::make('emailStudent')
                ->label('Email student')
                ->icon(Heroicon::Envelope)
                ->color('gray')
                ->url(fn (): string => "mailto:{$this->getRecord()->student->user->email}"),

            Action::make('viewOnArcScan')
                ->label('Student ArcScan')
                ->icon(Heroicon::ArrowTopRightOnSquare)
                ->color('gray')
                ->visible(fn (): bool => filled($this->getRecord()->student->wallet->address ?? $this->getRecord()->student->payout_address))
                ->url(function (): ?string {
                    $address = $this->getRecord()->student->wallet->address ?? $this->getRecord()->student->payout_address;

                    if (! $address) {
                        return null;
                    }

                    try {
                        return app(ArcNetworkGateway::class)->addressExplorerUrl($address);
                    } catch (Throwable) {
                        return null;
                    }
                })
                ->openUrlInNewTab(),

            EditAction::make()
                ->icon(Heroicon::PencilSquare),
        ];
    }
}
