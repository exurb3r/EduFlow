<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\Pages;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Enums\StudentBillCategory;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\Students\Widgets\StudentProfileSummaryWidget;
use App\Filament\Resources\Students\Widgets\StudentWalletWidget;
use App\Filament\Resources\TuitionAccounts\TuitionAccountResource;
use App\Models\AcademicTerm;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\TuitionAccount;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class ViewStudent extends ViewRecord
{
    protected static string $resource = StudentResource::class;

    #[\Override]
    protected function getHeaderWidgets(): array
    {
        return [
            StudentProfileSummaryWidget::class,
        ];
    }

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

            Action::make('createBill')
                ->label('New charge')
                ->icon('heroicon-m-receipt-percent')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->hasAnyRole(['admin', 'finance_officer', 'super_admin']) ?? false)
                ->modalHeading('Assess Non-Tuition Charge')
                ->modalDescription('Post an institutional fee (laboratory, registration, examination, or miscellaneous) for this student.')
                ->modalSubmitActionLabel('Post charge')
                ->schema([
                    Select::make('category')
                        ->label('Charge Category')
                        ->options([
                            StudentBillCategory::MISCELLANEOUS->value => StudentBillCategory::MISCELLANEOUS->label(),
                            StudentBillCategory::LABORATORY->value => StudentBillCategory::LABORATORY->label(),
                            StudentBillCategory::REGISTRATION->value => StudentBillCategory::REGISTRATION->label(),
                            StudentBillCategory::EXAMINATION->value => StudentBillCategory::EXAMINATION->label(),
                        ])
                        ->native(false)
                        ->required(),

                    Select::make('academic_term_id')
                        ->label('Academic Term (optional)')
                        ->options(fn (): array => AcademicTerm::query()->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload(),

                    TextInput::make('description')
                        ->label('Charge Description')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('e.g. Chemistry Laboratory Consumables Fee'),

                    TextInput::make('amount')
                        ->label('Amount (USDC)')
                        ->prefix('USDC')
                        ->inputMode('decimal')
                        ->placeholder('45.00')
                        ->required()
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(1000000000)
                        ->rules(['regex:/^\d+(\.\d{1,6})?$/']),

                    DatePicker::make('due_date')
                        ->label('Due Date (optional)')
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    $student = $this->getRecord();
                    $amountUnits = Money::fromDecimal((string) $data['amount'], CurrencyCode::USDC)->toBaseUnits();

                    $bill = StudentBill::query()->create([
                        'student_id' => $student->id,
                        'academic_term_id' => $data['academic_term_id'] ?? null,
                        'category' => $data['category'],
                        'description' => $data['description'],
                        'amount_base_units' => $amountUnits,
                        'paid_base_units' => 0,
                        'due_date' => $data['due_date'] ?? null,
                        'status' => StudentBill::STATUS_OPEN,
                    ]);

                    Notification::make()
                        ->title('Student charge issued')
                        ->body("Issued charge #{$bill->id} for {$student->user->name}.")
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

            EditAction::make()
                ->icon(Heroicon::PencilSquare),
        ];
    }
}
