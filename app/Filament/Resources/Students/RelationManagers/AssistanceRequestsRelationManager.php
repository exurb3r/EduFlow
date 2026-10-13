<?php

declare(strict_types=1);

namespace App\Filament\Resources\Students\RelationManagers;

use App\Models\AssistanceRequest;
use App\Models\Student;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AssistanceRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'assistanceRequests';

    protected static ?string $title = 'Assistance & Aid Requests';

    protected static string|BackedEnum|null $icon = Heroicon::HandRaised;

    #[\Override]
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        /** @var Student $ownerRecord */
        $count = $ownerRecord->assistanceRequests()->count();

        return $count > 0 ? (string) $count : null;
    }

    #[\Override]
    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    #[\Override]
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ticket_number')
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No assistance requests on file')
            ->emptyStateDescription('Financial assistance requests and emergency support tickets will appear here.')
            ->emptyStateIcon(Heroicon::HandRaised)
            ->columns([
                TextColumn::make('ticket_number')
                    ->label('Ticket #')
                    ->fontFamily('mono')
                    ->copyable()
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('category')
                    ->badge()
                    ->sortable(),

                TextColumn::make('subject')
                    ->limit(35)
                    ->tooltip(fn (AssistanceRequest $record): string => $record->subject)
                    ->searchable(),

                TextColumn::make('priority')
                    ->badge()
                    ->color(fn (mixed $state): string => match (is_object($state) && property_exists($state, 'value') ? $state->value : (string) $state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (mixed $state): string => match (is_object($state) && property_exists($state, 'value') ? $state->value : (string) $state) {
                        'approved', 'resolved' => 'success',
                        'escalated' => 'warning',
                        'rejected' => 'danger',
                        default => 'info',
                    })
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('M d, Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema([
                        Section::make('Request Details')
                            ->columns(2)
                            ->schema([
                                TextEntry::make('ticket_number')
                                    ->label('Ticket #')
                                    ->fontFamily('mono')
                                    ->copyable()
                                    ->weight('bold'),

                                TextEntry::make('category')
                                    ->badge(),

                                TextEntry::make('priority')
                                    ->badge(),

                                TextEntry::make('status')
                                    ->badge(),

                                TextEntry::make('created_at')
                                    ->label('Submitted At')
                                    ->dateTime(),

                                TextEntry::make('resolved_at')
                                    ->label('Resolved At')
                                    ->dateTime()
                                    ->placeholder('Not resolved yet'),
                            ]),

                        Section::make('Content')
                            ->columns(1)
                            ->schema([
                                TextEntry::make('subject')
                                    ->weight('bold'),

                                TextEntry::make('description'),

                                TextEntry::make('admin_notes')
                                    ->label('Admin Notes / Resolution')
                                    ->placeholder('No notes recorded yet'),
                            ]),
                    ]),
            ]);
    }
}
