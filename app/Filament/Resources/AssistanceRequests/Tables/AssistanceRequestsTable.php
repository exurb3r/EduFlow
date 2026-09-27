<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistanceRequests\Tables;

use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Enums\AssistanceStatus;
use App\Models\AssistanceRequest;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class AssistanceRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ticket_number')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('ticket_number')
                    ->label('Ticket #')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->weight('bold'),
                TextColumn::make('user.name')
                    ->label('Student')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->badge()
                    ->sortable(),
                TextColumn::make('priority')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->searchable()
                    ->limit(35),
                TextColumn::make('assignee.name')
                    ->label('Assigned To')
                    ->placeholder('Unassigned')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(AssistanceStatus::class),
                SelectFilter::make('category')
                    ->options(AssistanceCategory::class),
                SelectFilter::make('priority')
                    ->options(AssistancePriority::class),
            ])
            ->recordActions([
                Action::make('mark_in_progress')
                    ->label('Take Ticket')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn (AssistanceRequest $record): bool => $record->status === AssistanceStatus::PENDING)
                    ->requiresConfirmation()
                    ->action(function (AssistanceRequest $record): void {
                        $record->update([
                            'status' => AssistanceStatus::IN_PROGRESS,
                            'assigned_to' => $record->assigned_to ?? Auth::id(),
                        ]);
                    }),
                Action::make('resolve')
                    ->label('Resolve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (AssistanceRequest $record): bool => $record->status !== AssistanceStatus::RESOLVED)
                    ->schema([
                        Textarea::make('admin_notes')
                            ->label('Resolution Notes & Response')
                            ->helperText('Visible to the student on their dashboard.')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (AssistanceRequest $record, array $data): void {
                        $record->update([
                            'status' => AssistanceStatus::RESOLVED,
                            'admin_notes' => $data['admin_notes'],
                            'resolved_at' => now(),
                            'assigned_to' => $record->assigned_to ?? Auth::id(),
                        ]);
                    }),
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
