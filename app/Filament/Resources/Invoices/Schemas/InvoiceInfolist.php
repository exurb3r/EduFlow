<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Schemas;

use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\InvoiceSettlement;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice Reference & Vendor Dossier')
                    ->description('Institutional payable identifier, supplier profile, and delivery terms.')
                    ->icon(Heroicon::DocumentText)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'md' => 3,
                        ])->schema([
                            TextEntry::make('reference')
                                ->label('Invoice Reference #')
                                ->fontFamily('mono')
                                ->weight('bold')
                                ->badge()
                                ->color('gray')
                                ->copyable()
                                ->icon(Heroicon::Identification),

                            TextEntry::make('vendor.name')
                                ->label('Supplier / Vendor')
                                ->weight('bold')
                                ->icon(Heroicon::BuildingOffice),

                            TextEntry::make('vendor.category')
                                ->label('Vendor Category')
                                ->badge()
                                ->formatStateUsing(fn (?string $state): string => ucfirst($state ?? 'General')),

                            TextEntry::make('vendor.email')
                                ->label('Vendor Email Address')
                                ->icon(Heroicon::Envelope)
                                ->copyable()
                                ->placeholder('No contact email on file'),

                            TextEntry::make('vendor.wallet_address')
                                ->label('Vendor Payout Wallet')
                                ->fontFamily('mono')
                                ->placeholder('No on-chain address')
                                ->copyable(fn (Invoice $record): bool => filled($record->vendor?->wallet_address))
                                ->url(fn (Invoice $record): ?string => $record->vendor?->wallet_address ? app(ArcNetworkGateway::class)->addressExplorerUrl($record->vendor->wallet_address) : null)
                                ->openUrlInNewTab()
                                ->icon(Heroicon::Wallet),

                            TextEntry::make('vendor.status')
                                ->label('Vendor Compliance')
                                ->badge()
                                ->color(fn (Invoice $record): string => match ($record->vendor?->status) {
                                    'verified' => 'success',
                                    'pending' => 'warning',
                                    default => 'gray',
                                })
                                ->formatStateUsing(fn (Invoice $record): string => ucfirst($record->vendor?->status ?? 'Unverified')),
                        ]),
                    ]),

                Section::make('Financial Assessment & Budget Ledger')
                    ->description('Billed USDC obligations, departmental budget allocations, and payment deadlines.')
                    ->icon(Heroicon::Banknotes)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                            'md' => 3,
                        ])->schema([
                            TextEntry::make('amount')
                                ->label('Invoice Obligation (USDC)')
                                ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' USDC')
                                ->weight('bold')
                                ->color('primary')
                                ->icon(Heroicon::CurrencyDollar),

                            TextEntry::make('budget.name')
                                ->label('Allocated Department Budget')
                                ->placeholder('Unallocated Budget Line')
                                ->badge(fn (Invoice $record): bool => filled($record->budget_id))
                                ->color(fn (Invoice $record): string => filled($record->budget_id) ? 'info' : 'warning')
                                ->icon(Heroicon::Scale),

                            TextEntry::make('category')
                                ->label('Expenditure Category')
                                ->badge()
                                ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                            TextEntry::make('due_date')
                                ->label('Payment Due Date')
                                ->date('M d, Y')
                                ->icon(Heroicon::Calendar),

                            TextEntry::make('status')
                                ->label('Payment Lifecycle Status')
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    'auto_paid', 'paid' => 'success',
                                    'escalated' => 'danger',
                                    'held' => 'warning',
                                    'rejected' => 'gray',
                                    default => 'info',
                                })
                                ->icon(fn (string $state): string => match ($state) {
                                    'auto_paid', 'paid' => 'heroicon-m-check-circle',
                                    'escalated' => 'heroicon-m-shield-exclamation',
                                    'held' => 'heroicon-m-pause-circle',
                                    'rejected' => 'heroicon-m-x-circle',
                                    default => 'heroicon-m-clock',
                                })
                                ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state))),

                            TextEntry::make('organization.name')
                                ->label('Institution Entity')
                                ->icon(Heroicon::BuildingLibrary)
                                ->placeholder('Institution Default'),
                        ]),
                    ]),

                Section::make('On-Chain Arc Settlement & Evidence Verification')
                    ->description('Cryptographic proof that payment was actually executed and settled on the Arc blockchain.')
                    ->icon(Heroicon::Signal)
                    ->components([
                        Grid::make([
                            'default' => 1,
                            'sm' => 2,
                        ])->schema([
                            TextEntry::make('settlement_verdict')
                                ->label('Cryptographic Chain Verdict')
                                ->badge()
                                ->state(fn (Invoice $record): string => match (InvoiceSettlement::verdictFor($record)) {
                                    'verified' => 'Verified on Arc Testnet',
                                    'unsupported' => 'Receipt Invalid (Not on Chain)',
                                    'not_settled' => 'Awaiting Settlement Execution',
                                    default => 'Unverified / Verification Unavailable',
                                })
                                ->color(fn (Invoice $record): string => match (InvoiceSettlement::verdictFor($record)) {
                                    'verified' => 'success',
                                    'unsupported' => 'danger',
                                    'not_settled' => 'gray',
                                    default => 'info',
                                })
                                ->icon(fn (Invoice $record): string => match (InvoiceSettlement::verdictFor($record)) {
                                    'verified' => 'heroicon-m-check-badge',
                                    'unsupported' => 'heroicon-m-exclamation-triangle',
                                    default => 'heroicon-m-signal',
                                }),

                            TextEntry::make('blockchain_rail')
                                ->label('Settlement Blockchain Rail')
                                ->badge()
                                ->state(fn (): string => 'ARC-TESTNET (Chain ID: 5042002)')
                                ->color('info')
                                ->icon(Heroicon::Server),

                            TextEntry::make('transaction_hash')
                                ->label('Settlement Transaction Hash')
                                ->fontFamily('mono')
                                ->state(function (Invoice $record): string {
                                    $tx = Transaction::query()
                                        ->where('reference_type', Invoice::class)
                                        ->where('reference_id', $record->id)
                                        ->latest('id')
                                        ->first();

                                    return $tx?->provider_tx_hash ?? 'No transaction hash linked yet';
                                })
                                ->copyable(function (Invoice $record): bool {
                                    $tx = Transaction::query()
                                        ->where('reference_type', Invoice::class)
                                        ->where('reference_id', $record->id)
                                        ->latest('id')
                                        ->first();

                                    return filled($tx?->provider_tx_hash);
                                })
                                ->url(function (Invoice $record): ?string {
                                    $tx = Transaction::query()
                                        ->where('reference_type', Invoice::class)
                                        ->where('reference_id', $record->id)
                                        ->latest('id')
                                        ->first();

                                    if (! $tx?->provider_tx_hash) {
                                        return null;
                                    }

                                    try {
                                        return app(ArcNetworkGateway::class)->explorerUrl($tx->provider_tx_hash);
                                    } catch (Throwable) {
                                        return null;
                                    }
                                })
                                ->openUrlInNewTab()
                                ->helperText('Click to inspect full block receipt on ArcScan explorer.'),

                            TextEntry::make('settlement_explanation')
                                ->label('Audit Explanation')
                                ->state(fn (Invoice $record): string => InvoiceSettlement::explanationFor($record))
                                ->icon(Heroicon::ShieldCheck),
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
                                ->label('Internal Invoice ID')
                                ->fontFamily('mono')
                                ->prefix('#'),

                            TextEntry::make('created_at')
                                ->label('Logged At')
                                ->dateTime('M d, Y H:i:s'),

                            TextEntry::make('updated_at')
                                ->label('Last Profile Update')
                                ->dateTime('M d, Y H:i:s'),
                        ]),
                    ]),
            ]);
    }
}
