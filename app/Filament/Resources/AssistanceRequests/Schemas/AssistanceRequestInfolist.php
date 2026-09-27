<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistanceRequests\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AssistanceRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Assistance Ticket Overview')
                    ->components([
                        TextEntry::make('ticket_number')
                            ->label('Ticket #')
                            ->copyable()
                            ->weight('bold'),
                        TextEntry::make('user.name')
                            ->label('Student Name'),
                        TextEntry::make('user.email')
                            ->label('Student Email')
                            ->copyable(),
                        TextEntry::make('category')
                            ->badge(),
                        TextEntry::make('priority')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('assignee.name')
                            ->label('Assigned To')
                            ->placeholder('Unassigned'),
                        TextEntry::make('created_at')
                            ->label('Submitted At')
                            ->dateTime(),
                    ])->columns(3),

                Section::make('Request Content')
                    ->components([
                        TextEntry::make('subject')
                            ->label('Subject')
                            ->weight('bold')
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->label('Description')
                            ->columnSpanFull(),
                    ]),

                Section::make('Admin Resolution')
                    ->components([
                        TextEntry::make('admin_notes')
                            ->label('Admin Notes / Student Reply')
                            ->placeholder('No response yet provided.')
                            ->columnSpanFull(),
                        TextEntry::make('resolved_at')
                            ->label('Resolved At')
                            ->dateTime()
                            ->placeholder('Not resolved yet'),
                    ]),
            ]);
    }
}
