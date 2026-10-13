<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\Schemas;

use App\Models\Student;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
                                ->state(fn (Student $record): string => "Year {$record->year_level}"),

                            TextEntry::make('enrollment_status')
                                ->label('Enrollment Status')
                                ->badge()
                                ->color(fn (Student $record): string => $record->enrollmentStatusColor())
                                ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                            TextEntry::make('academic_status')
                                ->label('Academic Standing')
                                ->badge()
                                ->color(fn (Student $record): string => $record->academicStatusColor())
                                ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                            TextEntry::make('attendance_rate')
                                ->label('Attendance Rate')
                                ->state(fn (Student $record): string => $record->attendance_rate !== null ? ((float) $record->attendance_rate).'%' : 'Not recorded')
                                ->icon(Heroicon::Calendar),
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
                                ->helperText('Click to inspect payout destination on ArcScan.'),

                            TextEntry::make('wallet.address')
                                ->label('Circle Minted Agent Wallet')
                                ->fontFamily('mono')
                                ->placeholder('No Circle wallet minted yet')
                                ->copyable(fn (Student $record): bool => (bool) $record->wallet?->address)
                                ->url(fn (Student $record): ?string => $record->wallet?->address ? app(ArcNetworkGateway::class)->addressExplorerUrl($record->wallet->address) : null)
                                ->openUrlInNewTab()
                                ->helperText('Custodied via Circle Agent Stack on Arc Testnet.'),

                            TextEntry::make('disbursement_readiness')
                                ->label('Disbursement Status')
                                ->badge()
                                ->state(fn (Student $record): string => $record->hasValidPayoutAddress() ? 'Disbursement Ready' : 'Payout Address Missing / Invalid')
                                ->color(fn (Student $record): string => $record->hasValidPayoutAddress() ? 'success' : 'danger'),

                            TextEntry::make('chain')
                                ->label('Blockchain Rail')
                                ->badge()
                                ->state(fn (): string => 'ARC-TESTNET (Chain ID: 5042002)')
                                ->color('info'),
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
