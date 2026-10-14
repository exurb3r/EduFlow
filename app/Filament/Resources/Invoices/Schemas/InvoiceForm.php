<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice Identification & Payee Scope')
                    ->description('Payable reference, vendor profile, and expenditure classification.')
                    ->icon(Heroicon::DocumentText)
                    ->components([
                        TextInput::make('reference')
                            ->label('Invoice Reference #')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('INV-2026-001')
                            ->prefixIcon(Heroicon::Identification),

                        Select::make('vendor_id')
                            ->label('Supplier / Vendor')
                            ->relationship('vendor', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->prefixIcon(Heroicon::BuildingOffice),

                        Select::make('organization_id')
                            ->label('Institutional Entity')
                            ->relationship('organization', 'name')
                            ->required()
                            ->default(1)
                            ->prefixIcon(Heroicon::BuildingLibrary),

                        TextInput::make('category')
                            ->label('Expenditure Category')
                            ->datalist([
                                'operations',
                                'technology',
                                'facilities',
                                'equipment',
                                'laboratory',
                                'services',
                            ])
                            ->default('operations')
                            ->prefixIcon(Heroicon::Tag),
                    ])->columns(2),

                Section::make('Financial Assessment & Department Budget Allocation')
                    ->description('USDC obligation, department budget authorization, and payment due date.')
                    ->icon(Heroicon::Banknotes)
                    ->components([
                        TextInput::make('amount')
                            ->label('Invoice Amount (USDC)')
                            ->numeric()
                            ->prefix('USDC')
                            ->required()
                            ->placeholder('1500.00')
                            ->prefixIcon(Heroicon::CurrencyDollar),

                        Select::make('budget_id')
                            ->relationship('budget', 'name')
                            ->searchable()
                            ->preload()
                            ->label('Allocated Department Budget')
                            ->prefixIcon(Heroicon::Scale),

                        DatePicker::make('due_date')
                            ->label('Payment Due Date')
                            ->required()
                            ->native(false)
                            ->prefixIcon(Heroicon::Calendar),

                        Select::make('status')
                            ->label('Review & Settlement Lifecycle')
                            ->options([
                                'pending' => 'Pending Review',
                                'auto_paid' => 'Auto-Paid (Arc Network)',
                                'escalated' => 'Escalated to Supervisor',
                                'held' => 'Held for Safety Review',
                                'paid' => 'Paid (Authorized)',
                                'rejected' => 'Rejected',
                            ])
                            ->required()
                            ->native(false)
                            ->default('pending')
                            ->prefixIcon(Heroicon::CheckCircle),
                    ])->columns(2),
            ]);
    }
}
