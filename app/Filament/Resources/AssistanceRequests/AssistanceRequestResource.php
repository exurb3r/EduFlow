<?php

declare(strict_types=1);

namespace App\Filament\Resources\AssistanceRequests;

use App\Enums\AssistanceStatus;
use App\Filament\Resources\AssistanceRequests\Pages\CreateAssistanceRequest;
use App\Filament\Resources\AssistanceRequests\Pages\EditAssistanceRequest;
use App\Filament\Resources\AssistanceRequests\Pages\ListAssistanceRequests;
use App\Filament\Resources\AssistanceRequests\Pages\ViewAssistanceRequest;
use App\Filament\Resources\AssistanceRequests\Schemas\AssistanceRequestForm;
use App\Filament\Resources\AssistanceRequests\Schemas\AssistanceRequestInfolist;
use App\Filament\Resources\AssistanceRequests\Tables\AssistanceRequestsTable;
use App\Models\AssistanceRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AssistanceRequestResource extends Resource
{
    protected static ?string $model = AssistanceRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Lifebuoy;

    protected static ?string $recordTitleAttribute = 'ticket_number';

    protected static string|UnitEnum|null $navigationGroup = 'Student Services';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $count = AssistanceRequest::where('status', AssistanceStatus::PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return AssistanceRequestForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AssistanceRequestInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AssistanceRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssistanceRequests::route('/'),
            'create' => CreateAssistanceRequest::route('/create'),
            'view' => ViewAssistanceRequest::route('/{record}'),
            'edit' => EditAssistanceRequest::route('/{record}/edit'),
        ];
    }
}
