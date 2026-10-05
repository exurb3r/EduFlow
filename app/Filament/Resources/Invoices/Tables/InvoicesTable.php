<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Tables;

use App\Models\Invoice;
use App\Services\InvoiceSettlement;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->defaultSort('due_date', 'asc')
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference #')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),
                TextColumn::make('vendor.name')
                    ->label('Vendor')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' USDC')
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date()
                    ->sortable(),
                TextColumn::make('settlement_proof')
                    ->label('Chain Proof')
                    ->badge()
                    ->getStateUsing(function (Invoice $record): string {
                        // A paid status is EduFlow's own claim; only a settled
                        // transaction backs it. Never assert success on the
                        // status column alone.
                        if (! in_array($record->status, ['auto_paid', 'paid'], true)) {
                            return 'n/a';
                        }

                        $verdict = InvoiceSettlement::verdictFor($record);

                        return match ($verdict) {
                            'verified' => 'Verified on Arc',
                            'unsupported' => 'Receipt invalid',
                            default => 'Unverified',
                        };
                    })
                    ->color(function (Invoice $record): string {
                        if (! in_array($record->status, ['auto_paid', 'paid'], true)) {
                            return 'gray';
                        }

                        return match (InvoiceSettlement::verdictFor($record)) {
                            'verified' => 'success',
                            'unsupported' => 'danger',
                            default => 'info',
                        };
                    })
                    ->tooltip(function (Invoice $record): string {
                        if (! in_array($record->status, ['auto_paid', 'paid'], true)) {
                            return 'Not a settled payment.';
                        }

                        return InvoiceSettlement::explanationFor($record);
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'auto_paid', 'paid' => 'success',
                        'escalated' => 'danger',
                        'held' => 'warning',
                        'rejected' => 'gray',
                        default => 'info',
                    })
                    ->sortable(),
                TextColumn::make('budget.name')
                    ->label('Budget')
                    ->placeholder('Unbudgeted')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Logged At')
                    ->dateTime('M d, H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'auto_paid' => 'Auto-Paid',
                        'escalated' => 'Escalated',
                        'held' => 'Held',
                        'paid' => 'Paid',
                        'rejected' => 'Rejected',
                    ]),
                SelectFilter::make('vendor')
                    ->relationship('vendor', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
