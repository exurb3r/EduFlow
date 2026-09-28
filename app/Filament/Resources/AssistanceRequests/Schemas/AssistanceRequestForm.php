<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistanceRequests\Schemas;

use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Enums\AssistanceStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AssistanceRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Student Request')
                    ->description('Details of the assistance inquiry submitted by the student.')
                    ->components([
                        TextInput::make('ticket_number')
                            ->label('Ticket #')
                            ->disabled()
                            ->dehydrated()
                            ->placeholder('Auto-generated on creation'),
                        Select::make('user_id')
                            ->relationship('user', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->label('Student'),
                        Select::make('category')
                            ->options(AssistanceCategory::class)
                            ->required(),
                        Select::make('priority')
                            ->options(AssistancePriority::class)
                            ->required(),
                        Select::make('status')
                            ->options(AssistanceStatus::class)
                            ->required()
                            ->default(AssistanceStatus::PENDING),
                        Select::make('assigned_to')
                            ->relationship('assignee', 'name')
                            ->searchable()
                            ->preload()
                            ->label('Assigned Staff / Counselor'),
                        TextInput::make('subject')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label('Inquiry / Problem Description')
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                    ])->columns(2),

                Section::make('Staff Resolution & Notes')
                    ->description('Response provided to the student and resolution status.')
                    ->components([
                        Textarea::make('admin_notes')
                            ->label('Admin Notes / Resolution Response')
                            ->helperText('This response will be visible to the student on their EduFlow dashboard.')
                            ->rows(3)
                            ->columnSpanFull(),
                        DateTimePicker::make('resolved_at')
                            ->label('Resolved At')
                            ->nullable(),
                    ])->columns(2),
            ]);
    }
}
