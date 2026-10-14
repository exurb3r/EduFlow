<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Tables;

use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\InvoiceSettlement;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->defaultSort('due_date', 'asc')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['vendor', 'budget', 'organization']))
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference #')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->badge()
                    ->color('gray')
                    ->fontFamily('mono')
                    ->weight('bold')
                    ->icon(Heroicon::Identification),

                TextColumn::make('vendor.name')
                    ->label('Supplier / Vendor')
                    ->searchable(['vendors.name', 'vendors.email'])
                    ->description(fn (Invoice $record): string => ucfirst($record->category).' • '.($record->vendor?->email ?? 'Direct Vendor'))
                    ->weight('bold')
                    ->icon(Heroicon::BuildingOffice)
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Obligation')
                    ->formatStateUsing(fn ($state): string => number_format((float) $state, 2).' USDC')
                    ->weight('bold')
                    ->color('primary')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('budget.name')
                    ->label('Department Budget')
                    ->placeholder('Unallocated Line')
                    ->badge(fn (Invoice $record): bool => filled($record->budget_id))
                    ->color(fn (Invoice $record): string => filled($record->budget_id) ? 'info' : 'warning')
                    ->icon(Heroicon::Scale)
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label('Due Date')
                    ->date('M d, Y')
                    ->description(function (Invoice $record): ?string {
                        if (in_array($record->status, ['auto_paid', 'paid'], true)) {
                            return 'Settled';
                        }
                        if ($record->due_date && $record->due_date->isPast()) {
                            $days = abs((int) today()->diffInDays($record->due_date, false));

                            return "Overdue by {$days}d";
                        }
                        if ($record->due_date) {
                            $days = (int) today()->diffInDays($record->due_date, false);

                            return "Due in {$days}d";
                        }

                        return null;
                    })
                    ->color(function (Invoice $record): string {
                        if (in_array($record->status, ['auto_paid', 'paid'], true)) {
                            return 'gray';
                        }
                        if ($record->due_date && $record->due_date->isPast()) {
                            return 'danger';
                        }

                        return 'gray';
                    })
                    ->sortable(),

                TextColumn::make('settlement_proof')
                    ->label('Chain Proof')
                    ->badge()
                    ->getStateUsing(function (Invoice $record): string {
                        if (! in_array($record->status, ['auto_paid', 'paid'], true)) {
                            return 'Unsettled';
                        }

                        return match (InvoiceSettlement::verdictFor($record)) {
                            'verified' => 'Verified on Arc',
                            'unsupported' => 'Receipt Invalid',
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
                    ->icon(function (Invoice $record): string {
                        if (! in_array($record->status, ['auto_paid', 'paid'], true)) {
                            return 'heroicon-m-clock';
                        }

                        return match (InvoiceSettlement::verdictFor($record)) {
                            'verified' => 'heroicon-m-check-badge',
                            'unsupported' => 'heroicon-m-exclamation-triangle',
                            default => 'heroicon-m-signal',
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
                    ->label('Lifecycle')
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
                    ->formatStateUsing(fn (string $state): string => ucfirst(str_replace('_', ' ', $state)))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Logged At')
                    ->dateTime('M d, H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending Review',
                        'auto_paid' => 'Auto-Paid (Arc)',
                        'escalated' => 'Escalated to Human',
                        'held' => 'Held for Safety',
                        'paid' => 'Paid (Authorized)',
                        'rejected' => 'Rejected',
                    ]),

                SelectFilter::make('vendor')
                    ->label('Vendor')
                    ->relationship('vendor', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('budget')
                    ->label('Department Budget')
                    ->relationship('budget', 'name')
                    ->searchable()
                    ->preload(),

                Filter::make('overdue')
                    ->label('Overdue obligations')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotIn('status', ['auto_paid', 'paid', 'rejected'])
                        ->where('due_date', '<', today())),

                Filter::make('unbudgeted')
                    ->label('Unallocated budget only')
                    ->query(fn (Builder $query): Builder => $query->whereNull('budget_id')),
            ])
            ->recordActions([
                Action::make('verifySettlement')
                    ->label('Verify')
                    ->icon(Heroicon::ShieldCheck)
                    ->color('primary')
                    ->action(function (Invoice $record): void {
                        $verdict = InvoiceSettlement::verdictFor($record);
                        $explanation = InvoiceSettlement::explanationFor($record);

                        Notification::make()
                            ->title("Settlement Status: {$verdict}")
                            ->body($explanation)
                            ->color(match ($verdict) {
                                'verified' => 'success',
                                'unsupported' => 'danger',
                                default => 'info',
                            })
                            ->send();
                    }),

                ViewAction::make()
                    ->icon(Heroicon::Eye),

                EditAction::make()
                    ->icon(Heroicon::PencilSquare),

                Action::make('arcscan')
                    ->label('ArcScan')
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(function (Invoice $record): bool {
                        $tx = Transaction::query()
                            ->where('reference_type', Invoice::class)
                            ->where('reference_id', $record->id)
                            ->latest('id')
                            ->first();

                        return filled($tx?->provider_tx_hash) || filled($record->vendor?->wallet_address);
                    })
                    ->url(function (Invoice $record): ?string {
                        $tx = Transaction::query()
                            ->where('reference_type', Invoice::class)
                            ->where('reference_id', $record->id)
                            ->latest('id')
                            ->first();

                        if ($tx?->provider_tx_hash) {
                            try {
                                return app(ArcNetworkGateway::class)->explorerUrl($tx->provider_tx_hash);
                            } catch (Throwable) {
                                return null;
                            }
                        }

                        if ($record->vendor?->wallet_address) {
                            try {
                                return app(ArcNetworkGateway::class)->addressExplorerUrl($record->vendor->wallet_address);
                            } catch (Throwable) {
                                return null;
                            }
                        }

                        return null;
                    })
                    ->openUrlInNewTab(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    BulkAction::make('allocate_budget')
                        ->label('Allocate to Budget')
                        ->icon(Heroicon::Scale)
                        ->schema([
                            Select::make('budget_id')
                                ->label('Select Department Budget')
                                ->options(fn (): array => Budget::query()->pluck('name', 'id')->all())
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            foreach ($records as $record) {
                                $record->update(['budget_id' => $data['budget_id']]);
                            }

                            Notification::make()
                                ->title('Budget line updated')
                                ->body("Allocated {$records->count()} invoices to the selected budget.")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}
