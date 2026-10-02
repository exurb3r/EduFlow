<?php

declare(strict_types=1);

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Models\AiProvider;
use App\Services\Ai\AiProviderResolver;
use App\Settings\AiSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The switches that govern AI behaviour.
 *
 * Provider endpoints and their encrypted keys are managed separately under AI
 * Providers, because there can be many of them and each carries its own key.
 *
 * @property-read Schema $form
 */
class AiSettingsPage extends Page
{
    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'AI';

    protected static ?string $navigationLabel = 'AI';

    protected string $view = 'filament.clusters.settings.pages.ai-settings-page';

    public ?array $data = [];

    /**
     * @var array<string, mixed>
     */
    public array $status = [];

    public function mount(): void
    {
        $settings = app(AiSettings::class);

        $this->status = app(AiProviderResolver::class)->describe();

        $this->form->fill([
            'advisory_enabled' => $settings->advisory_enabled,
            'disclosure_accepted' => $settings->disclosure_accepted,
            'allow_settlement_proposals' => $settings->allow_settlement_proposals,
            'timeout_seconds' => $settings->timeout_seconds,
            'failover_provider' => $settings->failover_provider,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make('Current provider')
                        ->description('Resolved at request time. An admin-configured provider takes precedence over .env.')
                        ->schema([
                            TextEntry::make('status_name')
                                ->label('Active provider')
                                ->state(fn (): string => (string) ($this->status['name'] ?? 'none'))
                                ->badge()
                                ->color(fn (): string => ($this->status['usable'] ?? false) ? 'success' : 'warning'),

                            TextEntry::make('status_source')
                                ->label('Configured in')
                                ->state(fn (): string => ($this->status['source'] ?? 'env') === 'admin' ? 'Admin panel' : '.env (config/ai.php)'),
                        ])
                        ->columns(2),

                    Section::make('Advisory calls')
                        ->description('Model calls are advisory only. The deterministic policy engine remains authoritative and works with AI off.')
                        ->schema([
                            Toggle::make('advisory_enabled')
                                ->label('Allow advisory model calls')
                                ->helperText('Master switch. When off, no request leaves the application for a model call.')
                                ->live(),

                            Toggle::make('disclosure_accepted')
                                ->label('Student data may be sent to the provider')
                                ->helperText('Required before any advisory call runs. Advisory prompts include the student-stated reason for a request.')
                                ->disabled(fn (callable $get): bool => ! $get('advisory_enabled')),

                            TextInput::make('timeout_seconds')
                                ->label('Timeout (seconds)')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(120)
                                ->helperText('Kept short on purpose: advisory calls must never delay a payment.'),
                        ])
                        ->columns(2),

                    Section::make('Settlement proposals')
                        ->description('Off by default. When enabled, the settlement operator may propose disbursements through Approvable tools. Policy still decides whether a human must approve, either way.')
                        ->schema([
                            Toggle::make('allow_settlement_proposals')
                                ->label('Let the AI propose disbursements')
                                ->helperText('Leave off to keep the AI annotate-only: it may classify hardship and write narrative, never propose a payment.')
                                ->disabled(fn (callable $get): bool => ! $get('advisory_enabled')),
                        ]),

                    Section::make('Failover')
                        ->description('Used when the default provider rate-limits or is overloaded.')
                        ->schema([
                            Select::make('failover_provider')
                                ->label('Failover provider')
                                ->options(fn (): array => AiProvider::query()->usable()->pluck('name', 'id')->all())
                                ->placeholder('None')
                                ->native(false)
                                ->helperText('Optional. Leave empty to fail closed instead.'),
                        ]),

                    Section::make('Add or change an endpoint')
                        ->description('Providers and their API keys live on their own screen, where each key is encrypted at rest with the application key and never rendered back.')
                        ->schema([
                            TextEntry::make('providers_hint')
                                ->label('')
                                ->state('Use the "Manage providers" button above to add an OpenAI-compatible endpoint such as Ollama, LM Studio, vLLM or a gateway.')
                                ->color('gray'),
                        ])
                        ->collapsible()
                        ->collapsed(),
                ])
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save Settings')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $settings = app(AiSettings::class);
        $settings->advisory_enabled = (bool) ($data['advisory_enabled'] ?? false);
        $settings->disclosure_accepted = (bool) ($data['disclosure_accepted'] ?? false);
        $settings->allow_settlement_proposals = (bool) ($data['allow_settlement_proposals'] ?? false);
        $settings->timeout_seconds = (int) ($data['timeout_seconds'] ?? 20);
        $settings->failover_provider = $data['failover_provider'] ?? null;
        $settings->save();

        $this->status = app(AiProviderResolver::class)->describe();

        Notification::make()
            ->success()
            ->title('AI settings saved')
            ->send();
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('providers')
                ->label('Manage providers')
                ->icon('heroicon-o-server-stack')
                ->url(fn (): string => AiProviderResource::getUrl('index')),
        ];
    }
}
