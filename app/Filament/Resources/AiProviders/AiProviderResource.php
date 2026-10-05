<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiProviders;

use App\Filament\Clusters\Settings\Pages\AiSettingsPage;
use App\Models\AiProvider;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Admin CRUD for AI provider endpoints.
 *
 * The API key is never displayed. Once saved it is only ever written, never
 * read back into the form, because a blank field means "leave the stored key
 * alone" rather than "clear it" — so rotating a key is explicit and a viewer
 * with panel access cannot read a secret out of the DOM.
 */
class AiProviderResource extends Resource
{
    protected static ?string $model = AiProvider::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'AI Providers';

    protected static ?string $label = 'AI Provider';

    protected static ?string $pluralLabel = 'AI Providers';

    protected static ?string $modelLabel = 'AI provider';

    protected static ?int $navigationSort = 20;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Endpoint')
                    ->description('Any provider the Laravel AI SDK supports. Choose "OpenAI-compatible" for Ollama, LM Studio, vLLM, LiteLLM, Together, or a corporate gateway.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Display name')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Shown in the admin list only.'),

                        Select::make('driver')
                            ->label('Driver')
                            ->options(AiProvider::driverOptions())
                            ->required()
                            ->native(false)
                            ->live(),

                        TextInput::make('base_url')
                            ->label('Base URL')
                            ->url()
                            ->maxLength(255)
                            // Required for openai-compatible: it has no default endpoint.
                            ->required(fn (Get $get): bool => $get('driver') === 'openai-compatible')
                            ->helperText('e.g. http://127.0.0.1:11434/v1 for Ollama, or https://api.example.com/v1 for a gateway.')
                            ->columnSpanFull(),

                        TextInput::make('model')
                            ->label('Text model')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Used as the default model for this provider, e.g. llama3.1 or claude-sonnet-4.'),

                        KeyValue::make('headers')
                            ->label('Extra headers')
                            ->keyLabel('Header')
                            ->valueLabel('Value')
                            ->addActionLabel('Add header')
                            ->helperText('Some compatible gateways need an additional header, e.g. X-Tenant-Id.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Credentials')
                    ->description('The API key is encrypted at rest with the application key. It cannot be read back here once saved.')
                    ->schema([
                        TextInput::make('api_key')
                            ->label('API key')
                            ->password()
                            ->revealable()
                            ->maxLength(4096)
                            // Empty on edit means "keep the stored key".
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->helperText('Leave blank to keep the stored key. Most local endpoints need none at all.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Availability')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Active')
                            ->helperText('Inactive providers stay configured but are never called.')
                            ->default(true),

                        Toggle::make('is_default')
                            ->label('Default provider')
                            ->helperText('Used when a request does not name a provider. Only one provider can be the default.')
                            ->default(false),

                        TextInput::make('sort_order')
                            ->label('Sort order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Lower sorts first when several are active.'),
                    ])
                    ->columns(3),
            ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (AiProvider $record): ?string => $record->base_url),

                TextColumn::make('driver')
                    ->label('Driver')
                    ->badge()
                    ->sortable(),

                TextColumn::make('model')
                    ->label('Model')
                    ->searchable(),

                // Never render the key, not even masked: a masked value still
                // reveals the tail of a secret.
                TextColumn::make('api_key')
                    ->label('API key')
                    ->formatStateUsing(fn (?string $state): string => filled($state) ? 'Saved (encrypted)' : 'Not set')
                    ->badge()
                    ->color(fn (?string $state): string => filled($state) ? 'success' : 'gray'),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                Action::make('makeDefault')
                    ->label('Make default')
                    ->icon('heroicon-o-star')
                    ->visible(fn (AiProvider $record): bool => ! $record->is_default)
                    ->action(function (AiProvider $record): void {
                        AiProvider::clearDefault();
                        $record->update(['is_default' => true]);

                        Notification::make()
                            ->success()
                            ->title("{$record->name} is now the default provider")
                            ->send();
                    }),
                Action::make('testConnection')
                    ->label('Test connection')
                    ->icon('heroicon-o-signal')
                    ->action(fn (AiProvider $record) => AiProviderConnectionTest::run($record)),
            ])
            ->toolbarActions([
                Action::make('settings')
                    ->label('AI settings')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->url(fn (): string => AiSettingsPage::getUrl()),
            ]);
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiProviders::route('/'),
            'create' => Pages\CreateAiProvider::route('/create'),
            'edit' => Pages\EditAiProvider::route('/{record}/edit'),
        ];
    }

    #[\Override]
    public static function getNavigationBadge(): ?string
    {
        return (string) AiProvider::query()->usable()->count();
    }
}
