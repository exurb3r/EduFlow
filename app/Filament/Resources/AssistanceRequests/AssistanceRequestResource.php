<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistanceRequests;

use App\Enums\AssistanceStatus;
use App\Filament\Resources\AssistanceRequests\Pages\ListAssistanceRequests;
use App\Filament\Resources\AssistanceRequests\Pages\ViewAssistanceRequest;
use App\Models\AssistanceRequest;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AssistanceRequestResource extends Resource
{
    protected static ?string $model = AssistanceRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'ticket_number';

    protected static string|UnitEnum|null $navigationGroup = 'Student Services';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $count = AssistanceRequest::where('status', AssistanceStatus::PENDING->value)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('ticket_number')->label('Ticket #'),
            TextEntry::make('student.student_number')->label('Student number'),
            TextEntry::make('student.user.name')->label('Student'),
            TextEntry::make('academicTerm.name')->label('Academic term'),
            TextEntry::make('type')->badge(),
            TextEntry::make('requested_amount')->label('Requested amount (USDC)')
                ->formatStateUsing(fn (int|string|null $state): string => self::formatUsdc($state)),
            TextEntry::make('status')->badge(),
            TextEntry::make('submitted_at')->dateTime(),
            TextEntry::make('reason')->columnSpanFull(),
            RepeatableEntry::make('student.tuitionAccounts')->label('Tuition accounts')
                ->state(fn (AssistanceRequest $record) => $record->student?->tuitionAccounts()->with('academicTerm')->get() ?? collect())
                ->schema([
                    TextEntry::make('academicTerm.name')->label('Academic term'),
                    TextEntry::make('total_amount')->label('Total (USDC)')
                        ->formatStateUsing(fn (int|string|null $state): string => self::formatUsdc($state)),
                    TextEntry::make('paid_amount')->label('Paid (USDC)')
                        ->formatStateUsing(fn (int|string|null $state): string => self::formatUsdc($state)),
                ])->columns(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('ticket_number')->label('Ticket #')->searchable()->sortable(),
                TextColumn::make('student.student_number')->label('Student number')->searchable(),
                TextColumn::make('student.user.name')->label('Student')->searchable(),
                TextColumn::make('subject')->label('Subject')->searchable(),
                TextColumn::make('academicTerm.name')->label('Academic term'),
                TextColumn::make('requested_amount')->label('Requested amount (USDC)')
                    ->formatStateUsing(fn (int|string|null $state): string => self::formatUsdc($state))
                    ->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('submitted_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('academic_term_id')->label('Academic term')
                    ->relationship('academicTerm', 'name')->searchable()->preload(),
                SelectFilter::make('status')->options([
                    'submitted' => 'Submitted',
                    'pending' => 'Pending Review',
                    'in_progress' => 'In Progress',
                    'resolved' => 'Resolved',
                ]),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    /**
     * @return Builder<AssistanceRequest>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['student.user', 'academicTerm', 'user']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function formatUsdc(int|string|null $amount): string
    {
        $amount = (string) ($amount ?? 0);
        $digits = str_pad($amount, 7, '0', STR_PAD_LEFT);

        return substr($digits, 0, -6).'.'.substr($digits, -6);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssistanceRequests::route('/'),
            'view' => ViewAssistanceRequest::route('/{record}'),
        ];
    }
}
