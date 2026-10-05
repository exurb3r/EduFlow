<?php

declare(strict_types=1);

use App\Filament\Clusters\Settings\Pages\AiSettingsPage;
use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Filament\Resources\AiProviders\Pages\CreateAiProvider;
use App\Filament\Resources\AiProviders\Pages\EditAiProvider;
use App\Models\AiProvider;
use App\Models\User;
use App\Settings\AiSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * The admin surface for AI providers.
 *
 * The critical assertion is the last one: a stored key must never be rendered
 * back into the edit form, because panel access would then be enough to read a
 * secret. That is asserted against the live component state, not the view.
 */
beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'user'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    $this->artisan('migrate', ['--path' => 'database/settings', '--no-interaction' => true]);

    $this->admin = User::factory()->create()->assignRole('super_admin');
    $this->actingAs($this->admin);
    filament()->setCurrentPanel('admin');
});

test('ai providers resource is registered in the admin panel', function (): void {
    expect(AiProviderResource::getUrl('index', panel: 'admin'))->toContain('ai-providers');
});

test('ai settings page is registered in the admin panel', function (): void {
    $pages = Filament::getPanel('admin')->getPages();

    expect(collect($pages)->contains(fn (string $page): bool => $page === AiSettingsPage::class))->toBeTrue();
});

test('super admin can open the ai settings page', function (): void {
    $this->get(AiSettingsPage::getUrl())->assertSuccessful();
});

test('ai settings can be saved from the panel', function (): void {
    app()->forgetInstance(AiSettings::class);

    Livewire::test(AiSettingsPage::class)
        ->fillForm([
            'advisory_enabled' => true,
            'disclosure_accepted' => true,
            'allow_settlement_proposals' => false,
            'timeout_seconds' => 30,
            'failover_provider' => null,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(AiSettings::class);
    $settings = app(AiSettings::class);

    expect($settings->advisory_enabled)->toBeTrue()
        ->and($settings->disclosure_accepted)->toBeTrue()
        ->and($settings->allow_settlement_proposals)->toBeFalse()
        ->and($settings->timeout_seconds)->toBe(30)
        ->and($settings->mayCallProvider())->toBeTrue()
        // Annotate-only stays on even with both gates open.
        ->and($settings->mayProposeSettlements())->toBeFalse();
});

test('an openai-compatible provider can be created from the panel', function (): void {
    Livewire::test(CreateAiProvider::class)
        ->fillForm([
            'name' => 'Ollama on this laptop',
            'driver' => 'openai-compatible',
            'base_url' => 'http://127.0.0.1:11434/v1',
            'model' => 'llama3.1',
            'api_key' => '',
            'headers' => [],
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 1,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $provider = AiProvider::firstOrFail();

    expect($provider->driver)->toBe('openai-compatible')
        ->and($provider->base_url)->toBe('http://127.0.0.1:11434/v1')
        ->and($provider->model)->toBe('llama3.1')
        ->and($provider->is_default)->toBeTrue();
});

test('openai-compatible requires a base url', function (): void {
    Livewire::test(CreateAiProvider::class)
        ->fillForm([
            'name' => 'No URL',
            'driver' => 'openai-compatible',
            'base_url' => '',
            'model' => 'llama3.1',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 1,
        ])
        ->call('create')
        ->assertHasFormErrors(['base_url']);
});

test('saving an api key encrypts it at rest', function (): void {
    Livewire::test(CreateAiProvider::class)
        ->fillForm([
            'name' => 'Hosted',
            'driver' => 'openai-compatible',
            'base_url' => 'https://api.example.com/v1',
            'model' => 'some-model',
            'api_key' => 'sk-typed-into-the-form',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 1,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $raw = (string) DB::table('ai_providers')->value('api_key');

    expect($raw)->not->toContain('sk-typed-into-the-form')
        ->and($raw)->toStartWith('eyJ')
        ->and(AiProvider::firstOrFail()->api_key)->toBe('sk-typed-into-the-form');
});

test('a stored api key is never rendered back into the edit form', function (): void {
    $provider = AiProvider::create([
        'name' => 'Hosted',
        'driver' => 'openai-compatible',
        'base_url' => 'https://api.example.com/v1',
        'model' => 'some-model',
        'api_key' => 'sk-must-not-be-visible',
        'is_active' => true,
        'is_default' => true,
    ]);

    $component = Livewire::test(EditAiProvider::class, ['record' => $provider->getKey()]);

    // The secret must not appear anywhere in the component's rendered output.
    $rendered = (string) $component->html();

    expect($rendered)->not->toContain('sk-must-not-be-visible')
        // and it must not be sitting in the form state either
        ->and($component->get('data')['api_key'] ?? null)->toBeNull();
});

test('leaving the key blank on edit keeps the stored key', function (): void {
    $provider = AiProvider::create([
        'name' => 'Hosted',
        'driver' => 'openai-compatible',
        'base_url' => 'https://api.example.com/v1',
        'model' => 'some-model',
        'api_key' => 'sk-original-key',
        'is_active' => true,
        'is_default' => true,
    ]);

    Livewire::test(EditAiProvider::class, ['record' => $provider->getKey()])
        ->fillForm([
            'name' => 'Renamed',
            'driver' => 'openai-compatible',
            'base_url' => 'https://api.example.com/v1',
            'model' => 'some-model',
            'api_key' => '',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 0,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($provider->fresh()->name)->toBe('Renamed')
        ->and($provider->fresh()->api_key)->toBe('sk-original-key');
});

test('only one provider can be the default', function (): void {
    $first = AiProvider::create([
        'name' => 'First', 'driver' => 'openai-compatible', 'base_url' => 'https://a.test/v1',
        'model' => 'm', 'is_active' => true, 'is_default' => true,
    ]);

    Livewire::test(CreateAiProvider::class)
        ->fillForm([
            'name' => 'Second',
            'driver' => 'openai-compatible',
            'base_url' => 'https://b.test/v1',
            'model' => 'm',
            'is_active' => true,
            'is_default' => true,
            'sort_order' => 2,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(AiProvider::where('is_default', true)->count())->toBe(1)
        ->and(AiProvider::where('name', 'Second')->firstOrFail()->is_default)->toBeTrue()
        ->and($first->fresh()->is_default)->toBeFalse();
});
