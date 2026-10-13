<?php

declare(strict_types=1);

namespace App\Filament\Resources\TuitionAccounts\Schemas;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\TuitionAccount;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class TuitionAccountInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Financial & Settlement Ledger')
                    ->description('USDC balances and settlement status on Arc Network.')
                    ->icon(Heroicon::Banknotes)
                    ->components([
                        Grid::make([
                            'default' => 2,
                            'sm' => 2,
                            'md' => 4,
                        ])->schema([
                            TextEntry::make('status')
                                ->label('Account Status')
                                ->badge()
                                ->state(fn (TuitionAccount $record): string => $record->statusLabel())
                                ->color(fn (TuitionAccount $record): string => $record->statusColor())
                                ->icon(fn (TuitionAccount $record): string => match (true) {
                                    $record->isPaidInFull() => 'heroicon-m-check-circle',
                                    $record->isPartiallyPaid() => 'heroicon-m-clock',
                                    default => 'heroicon-m-exclamation-circle',
                                }),

                            TextEntry::make('total_amount')
                                ->label('Total Tuition')
                                ->state(fn (TuitionAccount $record): string => new Money($record->total_amount, CurrencyCode::USDC)->format(2).' USDC')
                                ->weight('bold')
                                ->color('primary')
                                ->helperText(fn (TuitionAccount $record): string => 'Base units: '.number_format($record->total_amount)),

                            TextEntry::make('paid_amount')
                                ->label('Amount Paid')
                                ->state(fn (TuitionAccount $record): string => new Money($record->paid_amount, CurrencyCode::USDC)->format(2).' USDC')
                                ->weight('bold')
                                ->color('success')
                                ->helperText(fn (TuitionAccount $record): string => 'Base units: '.number_format($record->paid_amount)),

                            TextEntry::make('remaining')
                                ->label('Remaining Balance')
                                ->state(fn (TuitionAccount $record): string => new Money($record->remainingAmount(), CurrencyCode::USDC)->format(2).' USDC')
                                ->weight('bold')
                                ->color(fn (TuitionAccount $record): string => $record->remainingAmount() === 0 ? 'success' : 'danger')
                                ->helperText(fn (TuitionAccount $record): string => 'Base units: '.number_format($record->remainingAmount())),
                        ]),

                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                        ])->schema([
                            TextEntry::make('progress')
                                ->label('Settlement Progress')
                                ->badge()
                                ->state(fn (TuitionAccount $record): string => $record->paymentProgressPercentage().'% Settled')
                                ->color(fn (TuitionAccount $record): string => match (true) {
                                    $record->isPaidInFull() => 'success',
                                    $record->isPartiallyPaid() => 'warning',
                                    default => 'gray',
                                })
                                ->helperText(fn (TuitionAccount $record): string => sprintf(
                                    '%s of %s settled towards this term',
                                    new Money($record->paid_amount, CurrencyCode::USDC)->format(2).' USDC',
                                    new Money($record->total_amount, CurrencyCode::USDC)->format(2).' USDC',
                                )),

                            TextEntry::make('currency')
                                ->label('Settlement Asset & Rail')
                                ->badge()
                                ->state(fn (): string => 'USDC (Arc Native • 6 decimals)')
                                ->color('info')
                                ->helperText('Integer base units via Circle Agent Stack on Arc Network.'),
                        ]),
                    ]),

                Section::make('Student Information')
                    ->description('Associated student credentials and Arc payout wallet.')
                    ->icon(Heroicon::AcademicCap)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'md' => 3,
                        ])->schema([
                            TextEntry::make('student.user.name')
                                ->label('Student Name')
                                ->weight('bold')
                                ->icon(Heroicon::User),

                            TextEntry::make('student.student_number')
                                ->label('Student Number')
                                ->fontFamily('mono')
                                ->copyable()
                                ->badge()
                                ->color('gray'),

                            TextEntry::make('student.user.email')
                                ->label('Email Address')
                                ->icon(Heroicon::Envelope)
                                ->copyable(),

                            TextEntry::make('student.program')
                                ->label('Academic Program')
                                ->icon(Heroicon::BookOpen),

                            TextEntry::make('student.year_level')
                                ->label('Year Level')
                                ->state(fn (TuitionAccount $record): string => 'Year '.$record->student->year_level),

                            TextEntry::make('student.enrollment_status')
                                ->label('Enrollment Status')
                                ->badge()
                                ->color(fn (?string $state): string => match ($state) {
                                    'enrolled' => 'success',
                                    'on_leave' => 'warning',
                                    'graduated' => 'info',
                                    default => 'gray',
                                }),

                            TextEntry::make('student.academic_status')
                                ->label('Academic Standing')
                                ->badge()
                                ->color(fn (?string $state): string => match ($state) {
                                    'qualified' => 'success',
                                    'probation' => 'warning',
                                    'suspended' => 'danger',
                                    default => 'gray',
                                }),

                            TextEntry::make('student.attendance_rate')
                                ->label('Attendance Rate')
                                ->state(fn (TuitionAccount $record): string => $record->student->attendance_rate !== null ? $record->student->attendance_rate.'%' : 'Not recorded'),

                            TextEntry::make('wallet_address')
                                ->label('Arc Payout Wallet')
                                ->fontFamily('mono')
                                ->state(fn (TuitionAccount $record): string => $record->student->wallet->address ?? $record->student->payout_address ?? 'No wallet assigned')
                                ->copyable(fn (TuitionAccount $record): bool => (bool) ($record->student->wallet->address ?? $record->student->payout_address))
                                ->url(fn (TuitionAccount $record): ?string => ($address = $record->student->wallet->address ?? $record->student->payout_address) ? app(ArcNetworkGateway::class)->addressExplorerUrl($address) : null)
                                ->openUrlInNewTab()
                                ->helperText('Click to inspect address on ArcScan explorer.'),
                        ]),
                    ]),

                Section::make('Academic Term Details')
                    ->description('Academic session calendar and term parameters.')
                    ->icon(Heroicon::CalendarDays)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 3,
                        ])->schema([
                            TextEntry::make('academicTerm.name')
                                ->label('Term Name')
                                ->weight('bold'),

                            TextEntry::make('term_period')
                                ->label('Term Schedule')
                                ->icon(Heroicon::Clock)
                                ->state(fn (TuitionAccount $record): string => ($record->academicTerm->starts_on?->format('M d, Y') ?? 'N/A').' — '.($record->academicTerm->ends_on?->format('M d, Y') ?? 'N/A')),

                            TextEntry::make('term_status')
                                ->label('Term Status')
                                ->badge()
                                ->state(fn (TuitionAccount $record): string => $record->academicTerm->isActive() ? 'Active Term' : 'Inactive / Concluded')
                                ->color(fn (TuitionAccount $record): string => $record->academicTerm->isActive() ? 'success' : 'gray'),
                        ]),
                    ]),

                Section::make('Audit Information')
                    ->description('System identifiers and lifecycle timestamps.')
                    ->collapsed()
                    ->icon(Heroicon::InformationCircle)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 3,
                        ])->schema([
                            TextEntry::make('id')
                                ->label('Tuition Account ID')
                                ->fontFamily('mono')
                                ->prefix('#'),

                            TextEntry::make('created_at')
                                ->label('Created At')
                                ->dateTime('M d, Y H:i:s'),

                            TextEntry::make('updated_at')
                                ->label('Last Updated')
                                ->dateTime('M d, Y H:i:s'),
                        ]),
                    ]),
            ]);
    }
}
