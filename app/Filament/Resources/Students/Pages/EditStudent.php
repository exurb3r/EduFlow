<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\Pages;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\TuitionAccounts\TuitionAccountResource;
use App\Models\AcademicTerm;
use App\Models\Student;
use App\Models\TuitionAccount;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Throwable;
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
    public function getSubheading(): ?string
    {
        $student = $this->getRecord();

        return "{$student->user->name} ({$student->student_number}) • {$student->program}";
    }

    #[\Override]
    protected function getSavedNotificationTitle(): ?string
    {
        return 'Student profile updated';
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()
                ->icon(Heroicon::Eye),

            Action::make('createTuitionAccount')
                ->label('New tuition account')
                ->icon('heroicon-m-banknotes')
                ->color('primary')
                ->visible(fn (): bool => Gate::allows('create', TuitionAccount::class))
                ->modalHeading('Create Tuition Account')
                ->modalDescription('Open a tuition account for this student for a selected academic term.')
                ->modalSubmitActionLabel('Create account')
                ->schema([
                    Select::make('academic_term_id')
                        ->label('Academic Term')
                        ->options(fn (): array => AcademicTerm::query()->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->required(),

                    TextInput::make('total_amount')
                        ->label('Total tuition (USDC)')
                        ->prefix('USDC')
                        ->inputMode('decimal')
                        ->placeholder('300.00')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1000000000)
                        ->rules(['regex:/^\d+(\.\d{1,6})?$/']),

                    TextInput::make('paid_amount')
                        ->label('Amount paid (USDC)')
                        ->prefix('USDC')
                        ->inputMode('decimal')
                        ->placeholder('0.00')
                        ->default('0.00')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1000000000)
                        ->rules(['regex:/^\d+(\.\d{1,6})?$/']),
                ])
                ->action(function (array $data): void {
                    $student = $this->getRecord();
                    $totalUnits = Money::fromDecimal((string) $data['total_amount'], CurrencyCode::USDC)->toBaseUnits();
                    $paidUnits = Money::fromDecimal((string) $data['paid_amount'], CurrencyCode::USDC)->toBaseUnits();

                    TuitionAccountResource::assertUniqueAccount([
                        'student_id' => $student->id,
                        'academic_term_id' => $data['academic_term_id'],
                    ]);

                    $account = TuitionAccount::query()->create([
                        'student_id' => $student->id,
                        'academic_term_id' => $data['academic_term_id'],
                        'total_amount' => $totalUnits,
                        'paid_amount' => $paidUnits,
                    ]);

                    Notification::make()
                        ->title('Tuition account created')
                        ->body("Created tuition account #{$account->id} for {$student->user->name}.")
                        ->success()
                        ->send();
                }),

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
                    } catch (Throwable) {
                        return null;
                    }
                })
                ->openUrlInNewTab(),

            Action::make('emailStudent')
                ->label('Email student')
                ->icon(Heroicon::Envelope)
                ->color('gray')
                ->url(fn (): string => "mailto:{$this->getRecord()->user->email}"),

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
