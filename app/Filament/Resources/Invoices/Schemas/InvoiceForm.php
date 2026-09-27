<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice Information')
                    ->components([
                        TextInput::make('reference')
                            ->label('Invoice Reference #')
                            ->required()
                            ->maxLength(255),
                        Select::make('organization_id')
                            ->relationship('organization', 'name')
                            ->required()
                            ->default(1),
                        Select::make('vendor_id')
                            ->relationship('vendor', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Select::make('budget_id')
                            ->relationship('budget', 'name')
                            ->searchable()
                            ->preload()
                            ->label('Allocated Budget'),
                        TextInput::make('amount')
                            ->numeric()
                            ->prefix('USDC')
                            ->required(),
                        DatePicker::make('due_date')
                            ->required(),
                        Select::make('status')
                            ->options([
                                'pending' => 'Pending Review',
                                'auto_paid' => 'Auto-Paid (Arc)',
                                'escalated' => 'Escalated to Human',
                                'held' => 'Held for Safety',
                                'paid' => 'Paid (Authorized)',
                                'rejected' => 'Rejected',
                            ])
                            ->required()
                            ->default('pending'),
                        TextInput::make('category')
                            ->default('operations'),
                    ])->columns(2),
            ]);
    }
}
