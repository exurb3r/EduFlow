<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\Schemas;

use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Models\Student;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class StudentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Academic & Curriculum Standing')
                    ->description('Degree program, enrollment standing, and institutional qualification.')
                    ->icon(Heroicon::AcademicCap)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'md' => 3,
                        ])->schema([
                            TextEntry::make('program')
                                ->label('Degree Program')
                                ->icon(Heroicon::BookOpen)
                                ->weight('bold'),

                            TextEntry::make('year_level')
                                ->label('Curriculum Year')
                                ->icon(Heroicon::AcademicCap)
                                ->state(fn (Student $record): string => "Year {$record->year_level}"),

                            TextEntry::make('enrollment_status')
                                ->label('Enrollment Status')
                                ->badge()
                                ->color(fn (Student $record): string => $record->enrollmentStatusColor())
                                ->icon(fn (string $state): string => match ($state) {
                                    'enrolled' => 'heroicon-m-check-circle',
                                    'on_leave' => 'heroicon-m-pause-circle',
                                    'graduated' => 'heroicon-m-academic-cap',
                                    default => 'heroicon-m-x-circle',
                                })
                                ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                            TextEntry::make('academic_status')
                                ->label('Academic Standing')
                                ->badge()
                                ->color(fn (Student $record): string => $record->academicStatusColor())
                                ->icon(fn (string $state): string => match ($state) {
                                    'qualified' => 'heroicon-m-check-badge',
                                    'probation' => 'heroicon-m-exclamation-triangle',
                                    'suspended', 'dismissed' => 'heroicon-m-shield-exclamation',
                                    default => 'heroicon-m-information-circle',
                                })
                                ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                            TextEntry::make('attendance_rate')
                                ->label('Attendance Rate')
                                ->state(fn (Student $record): string => $record->attendance_rate !== null ? ((float) $record->attendance_rate).'%' : 'Not recorded')
                                ->badge(fn (Student $record): bool => $record->attendance_rate !== null)
                                ->color(fn (Student $record): string => match (true) {
                                    $record->attendance_rate === null => 'gray',
                                    (float) $record->attendance_rate >= 90.0 => 'success',
                                    (float) $record->attendance_rate >= 75.0 => 'warning',
                                    default => 'danger',
                                })
                                ->icon(Heroicon::Calendar),

                            TextEntry::make('aid_eligibility')
                                ->label('Financial Aid Qualification')
                                ->badge()
                                ->state(fn (Student $record): string => ($record->academic_status === 'qualified' && ($record->attendance_rate === null || (float) $record->attendance_rate >= 80.0) && $record->hasValidPayoutAddress()) ? 'Aid Qualified' : 'Review Required')
                                ->color(fn (Student $record): string => ($record->academic_status === 'qualified' && ($record->attendance_rate === null || (float) $record->attendance_rate >= 80.0) && $record->hasValidPayoutAddress()) ? 'success' : 'warning')
                                ->icon(fn (Student $record): string => ($record->academic_status === 'qualified' && ($record->attendance_rate === null || (float) $record->attendance_rate >= 80.0) && $record->hasValidPayoutAddress()) ? 'heroicon-m-check-badge' : 'heroicon-m-exclamation-triangle'),
                        ]),
                    ]),

                Section::make('User Account & Personal Identity')
                    ->description('Linked portal credentials and identity verification.')
                    ->icon(Heroicon::User)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'md' => 3,
                        ])->schema([
                            TextEntry::make('user.name')
                                ->label('Student Name')
                                ->weight('bold')
                                ->icon(Heroicon::User),

                            TextEntry::make('student_number')
                                ->label('Institutional ID')
                                ->fontFamily('mono')
                                ->badge()
                                ->color('gray')
                                ->icon(Heroicon::Identification)
                                ->copyable(),

                            TextEntry::make('user.email')
                                ->label('Email Address')
                                ->icon(Heroicon::Envelope)
                                ->copyable(),

                            TextEntry::make('user.id')
                                ->label('User Account ID')
                                ->fontFamily('mono')
                                ->prefix('#')
                                ->helperText('System authentication user ID.'),

                            TextEntry::make('user.email_verified_at')
                                ->label('Email Verification')
                                ->state(fn (Student $record): string => $record->user->email_verified_at ? 'Verified ('.$record->user->email_verified_at->format('M d, Y').')' : 'Unverified')
                                ->badge()
                                ->color(fn (Student $record): string => $record->user->email_verified_at ? 'success' : 'warning'),

                            TextEntry::make('user.created_at')
                                ->label('Account Created')
                                ->dateTime('M d, Y'),
                        ]),
                    ]),

                Section::make('On-Chain Arc Wallet & Disbursements')
                    ->description('Settlement wallet on Arc Network for automated financial aid and assistance.')
                    ->icon(Heroicon::Wallet)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                        ])->schema([
                            TextEntry::make('payout_address')
                                ->label('Payout Wallet Address')
                                ->fontFamily('mono')
                                ->placeholder('No payout address set')
                                ->copyable(fn (Student $record): bool => filled($record->payout_address))
                                ->url(fn (Student $record): ?string => $record->payout_address ? app(ArcNetworkGateway::class)->addressExplorerUrl($record->payout_address) : null)
                                ->openUrlInNewTab()
                                ->icon(Heroicon::Wallet)
                                ->helperText('Click to inspect payout destination on ArcScan.'),

                            TextEntry::make('wallet.address')
                                ->label('Circle Minted Agent Wallet')
                                ->fontFamily('mono')
                                ->placeholder('No Circle wallet minted yet')
                                ->copyable(fn (Student $record): bool => (bool) $record->wallet?->address)
                                ->url(fn (Student $record): ?string => $record->wallet?->address ? app(ArcNetworkGateway::class)->addressExplorerUrl($record->wallet->address) : null)
                                ->openUrlInNewTab()
                                ->icon(Heroicon::CpuChip)
                                ->helperText('Custodied via Circle Agent Stack on Arc Testnet.'),

                            TextEntry::make('disbursement_readiness')
                                ->label('Disbursement Status')
                                ->badge()
                                ->state(fn (Student $record): string => $record->hasValidPayoutAddress() ? 'Disbursement Ready' : 'Payout Address Missing / Invalid')
                                ->color(fn (Student $record): string => $record->hasValidPayoutAddress() ? 'success' : 'danger')
                                ->icon(fn (Student $record): string => $record->hasValidPayoutAddress() ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle'),

                            TextEntry::make('chain')
                                ->label('Blockchain Rail')
                                ->badge()
                                ->state(fn (): string => 'ARC-TESTNET (Chain ID: 5042002)')
                                ->color('info')
                                ->icon(Heroicon::Signal),
                        ]),
                    ]),

                Section::make('Financial Overview & Ledger Summary')
                    ->description('Consolidated balances across all tuition terms, non-tuition charges, and assistance applications.')
                    ->icon(Heroicon::Banknotes)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'md' => 3,
                        ])->schema([
                            TextEntry::make('total_tuition_owed')
                                ->label('Total Tuition Assessed')
                                ->state(fn (Student $record): string => (new Money($record->totalTuitionOwed(), CurrencyCode::USDC))->format(2).' USDC')
                                ->weight('bold')
                                ->icon(Heroicon::CurrencyDollar),

                            TextEntry::make('total_tuition_paid')
                                ->label('Total Tuition Paid')
                                ->state(fn (Student $record): string => (new Money($record->totalTuitionPaid(), CurrencyCode::USDC))->format(2).' USDC')
                                ->weight('bold')
                                ->color('success')
                                ->icon(Heroicon::CheckCircle),

                            TextEntry::make('outstanding_tuition_balance')
                                ->label('Outstanding Tuition Debt')
                                ->state(fn (Student $record): string => (new Money($record->totalTuitionBalance(), CurrencyCode::USDC))->format(2).' USDC')
                                ->weight('bold')
                                ->badge()
                                ->color(fn (Student $record): string => $record->totalTuitionBalance() > 0 ? 'danger' : 'success')
                                ->icon(fn (Student $record): string => $record->totalTuitionBalance() > 0 ? 'heroicon-m-exclamation-circle' : 'heroicon-m-check-circle'),

                            TextEntry::make('unpaid_bills')
                                ->label('Non-Tuition Open Bills')
                                ->state(function (Student $record): string {
                                    $openBills = $record->bills->whereIn('status', ['open', 'partially_paid']);
                                    $unpaidUnits = (int) $openBills->sum(fn ($b): int => (int) ($b->amount_base_units - $b->paid_base_units));

                                    return (new Money($unpaidUnits, CurrencyCode::USDC))->format(2)." USDC ({$openBills->count()} open)";
                                })
                                ->badge()
                                ->color(fn (Student $record): string => $record->bills->whereIn('status', ['open', 'partially_paid'])->count() > 0 ? 'warning' : 'gray')
                                ->icon(Heroicon::ReceiptPercent),

                            TextEntry::make('assistance_requests_count')
                                ->label('Assistance Applications')
                                ->state(fn (Student $record): string => $record->assistanceRequests->count().' '.Str::plural('ticket', $record->assistanceRequests->count()))
                                ->badge()
                                ->color('info')
                                ->icon(Heroicon::HandRaised),

                            TextEntry::make('tuition_accounts_count')
                                ->label('Active Tuition Terms')
                                ->state(fn (Student $record): string => $record->tuitionAccounts->count().' '.Str::plural('term account', $record->tuitionAccounts->count()))
                                ->badge()
                                ->color('primary')
                                ->icon(Heroicon::DocumentText),
                        ]),
                    ]),

                Section::make('Audit Information')
                    ->description('System record identifiers and tracking timestamps.')
                    ->collapsed()
                    ->icon(Heroicon::InformationCircle)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 3,
                        ])->schema([
                            TextEntry::make('id')
                                ->label('Student Record ID')
                                ->fontFamily('mono')
                                ->prefix('#'),

                            TextEntry::make('created_at')
                                ->label('Record Created At')
                                ->dateTime('M d, Y H:i:s'),

                            TextEntry::make('updated_at')
                                ->label('Last Profile Update')
                                ->dateTime('M d, Y H:i:s'),
                        ]),
                    ]),
            ]);
    }
}
