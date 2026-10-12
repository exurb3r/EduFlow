<?php

declare(strict_types=1);

use App\Ai\Agents\AdminAssistantAgent;
use App\Filament\Clusters\Settings\Pages\AiSettingsPage;
use App\Filament\Pages\AdminAiChat;
use App\Filament\Resources\AiProviders\AiProviderConnectionTest;
use App\Http\Responses\AiSseResponse;
use App\Models\AiProvider;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Settings\AiSettings;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Prompts\AgentPrompt;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'user'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    $this->artisan('migrate', ['--path' => 'database/settings', '--no-interaction' => true]);

    $this->admin = User::factory()->create()->assignRole('super_admin');
    $this->regularUser = User::factory()->create()->assignRole('user');
});

test('arc ai page is accessible by super admin', function (): void {
    $this->actingAs($this->admin);
    filament()->setCurrentPanel('admin');

    $this->get(AdminAiChat::getUrl())->assertSuccessful();
});

test('arc ai shows the saved default provider name and model after refresh', function (): void {
    $this->actingAs($this->admin);
    filament()->setCurrentPanel('admin');

    $provider = AiProvider::create([
        'name' => 'Koamishin',
        'driver' => 'openai-compatible',
        'base_url' => 'https://gateway.example.com/v1',
        'model' => 'cx/gpt-6.1-sol',
        'api_key' => 'sk-configured-secret',
        'is_active' => true,
        'is_default' => true,
    ]);

    foreach (range(1, 2) as $refresh) {
        $this->get(AdminAiChat::getUrl())
            ->assertSuccessful()
            ->assertSee("providerLabel: 'Koamishin'", escape: false)
            ->assertSee("modelName: 'cx/gpt-6.1-sol'", escape: false)
            ->assertDontSee('gpt-4o-mini')
            ->assertDontSee('sk-configured-secret');
    }

    $provider->update(['model' => 'configured-replacement-model']);

    $this->get(AdminAiChat::getUrl())
        ->assertSuccessful()
        ->assertSee("modelName: 'configured-replacement-model'", escape: false)
        ->assertDontSee('cx/gpt-6.1-sol');
});

test('arc ai uses environment provider metadata without inventing a model', function (): void {
    $this->actingAs($this->admin);
    filament()->setCurrentPanel('admin');
    config([
        'ai.default' => 'local',
        'ai.providers.local.driver' => 'openai-compatible',
        'ai.providers.local.models.text.default' => 'local-configured-model',
    ]);

    $this->get(AdminAiChat::getUrl())
        ->assertSuccessful()
        ->assertSee("providerLabel: 'local'", escape: false)
        ->assertSee("modelName: 'local-configured-model'", escape: false)
        ->assertDontSee('gpt-4o-mini');

    config(['ai.providers.local.models.text.default' => null]);

    $this->get(AdminAiChat::getUrl())
        ->assertSuccessful()
        ->assertSee("modelName: 'Provider default'", escape: false)
        ->assertDontSee('gpt-4o-mini');
});

test('arc ai page denies access to non-admin users', function (): void {
    $this->actingAs($this->regularUser);
    filament()->setCurrentPanel('admin');

    $this->get(AdminAiChat::getUrl())->assertForbidden();
});

test('chat sessions api can list, create, search and delete sessions', function (): void {
    $this->actingAs($this->admin);
    $settings = app(AiSettings::class);
    $settings->manual_chat_enabled = true;
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    $settings->save();

    // Create session
    $createResponse = $this->postJson('/api/chats', [
        'title' => 'Test Discussion',
    ])->assertSuccessful();

    $sessionId = $createResponse->json('data.id');
    $sessionUuid = $createResponse->json('data.uuid');
    expect($sessionId)->toBeInt()
        ->and($sessionUuid)->not->toBeEmpty()
        ->and($createResponse->json('session.id'))->toBe($sessionId)
        ->and($createResponse->json('session.uuid'))->toBe($sessionUuid);

    expect(ChatSession::findOrFail($sessionId)->source)->toBe('arc');

    // List sessions
    $listResponse = $this->getJson('/api/chats')->assertSuccessful();
    expect($listResponse->json('data'))->toHaveCount(1)
        ->and($listResponse->json('data.0.id'))->toBe($sessionId);

    // Search sessions
    $searchResponse = $this->getJson('/api/chats/search?q=Discussion')->assertSuccessful();
    expect($searchResponse->json('data'))->toHaveCount(1);

    // Delete session
    $this->deleteJson('/api/chats/'.$sessionUuid)->assertSuccessful();
    expect(ChatSession::find($sessionId))->toBeNull();
});

test('chat messages api returns messages for session', function (): void {
    $this->actingAs($this->admin);

    $session = ChatSession::create([
        'user_id' => $this->admin->id,
        'title' => 'Existing Session',
    ]);

    $session->messages()->create([
        'role' => 'user',
        'content' => 'Hello ARC',
    ]);

    $session->messages()->create([
        'role' => 'assistant',
        'content' => 'Hello Administrator!',
        'thinking' => 'Processing student data...',
    ]);

    $response = $this->getJson("/api/chats/{$session->uuid}/messages")->assertSuccessful();
    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.content'))->toBe('Hello ARC')
        ->and($response->json('data.1.thinking'))->toBe('Processing student data...');
});

test('chat stream endpoint streams response and persists text with configured timeout', function (): void {
    $this->actingAs($this->admin);
    $settings = app(AiSettings::class);
    $settings->manual_chat_enabled = true;
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    $settings->timeout_seconds = 7;
    $settings->save();

    $session = ChatSession::create([
        'user_id' => $this->admin->id,
        'title' => 'Stream Session',
    ]);

    AdminAssistantAgent::fake(['ARC response stream']);

    $response = $this->postJson("/api/chats/{$session->uuid}/stream", [
        'message' => 'Analyze our treasury position',
    ]);

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

    // User message should be saved immediately
    $userMsg = ChatMessage::where('session_id', $session->id)->where('role', 'user')->first();
    expect($userMsg)->not->toBeNull()
        ->and($userMsg->content)->toBe('Analyze our treasury position');

    $content = $response->streamedContent();
    expect($content)->toContain('"type":"text_delta"', '"delta":"ARC"', 'data: [DONE]');
    expect($session->messages()->where('role', 'assistant')->sole()->content)->toBe('ARC response stream');
    AdminAssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->timeout === 7);
});

test('manual chat setting defaults off and persists across settings instances', function (): void {
    expect(AiSettings::defaults()['manual_chat_enabled'])->toBeFalse()
        ->and(app(AiSettings::class)->manual_chat_enabled)->toBeFalse();

    $this->assertDatabaseHas('settings', [
        'group' => 'ai',
        'name' => 'manual_chat_enabled',
        'payload' => 'false',
    ]);

    $settings = app(AiSettings::class);
    $settings->manual_chat_enabled = true;
    $settings->save();

    expect((new AiSettings)->manual_chat_enabled)->toBeTrue();
});

test('manual chat off denies creation and prompts without side effects', function (string $endpoint): void {
    $this->actingAs($this->admin);
    $settings = app(AiSettings::class);
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    $settings->save();
    AdminAssistantAgent::fake();
    $resolver = Mockery::mock(new AiProviderResolver($settings));
    $resolver->shouldNotReceive('resolve');
    $this->app->instance(AiProviderResolver::class, $resolver);
    Http::preventStrayRequests();

    $session = ChatSession::create(['user_id' => $this->admin->id, 'title' => 'New chat']);
    $before = $session->fresh()->getAttributes();
    $path = $endpoint === 'create' ? '/api/chats' : "/api/chats/{$session->uuid}/{$endpoint}";

    $this->postJson($path, ['message' => 'Blocked prompt'])->assertForbidden();

    expect(ChatSession::count())->toBe(1)
        ->and(ChatMessage::count())->toBe(0)
        ->and($session->fresh()->getAttributes())->toBe($before);
    AdminAssistantAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with(['create', 'messages', 'stream']);

test('manual chat needs both advisory and disclosure gates before writes', function (string $endpoint, bool $advisory, bool $disclosure): void {
    $this->actingAs($this->admin);
    $settings = app(AiSettings::class);
    $settings->manual_chat_enabled = true;
    $settings->advisory_enabled = $advisory;
    $settings->disclosure_accepted = $disclosure;
    $settings->save();
    AdminAssistantAgent::fake();
    $resolver = Mockery::mock(new AiProviderResolver($settings));
    $resolver->shouldNotReceive('resolve');
    $this->app->instance(AiProviderResolver::class, $resolver);
    Http::preventStrayRequests();

    $session = ChatSession::create(['user_id' => $this->admin->id, 'title' => 'New chat']);
    $before = $session->fresh()->getAttributes();
    $path = $endpoint === 'create' ? '/api/chats' : "/api/chats/{$session->uuid}/{$endpoint}";

    $this->postJson($path, ['message' => 'Blocked prompt'])->assertForbidden();

    expect(ChatSession::count())->toBe(1)
        ->and(ChatMessage::count())->toBe(0)
        ->and($session->fresh()->getAttributes())->toBe($before);
    AdminAssistantAgent::assertNeverPrompted();
    Http::assertNothingSent();
})->with(['create', 'messages', 'stream'])->with([
    'advisory off' => [false, true],
    'disclosure off' => [true, false],
    'both off' => [false, false],
]);

test('non-admin owners cannot access any chat action', function (string $method, string $suffix): void {
    $settings = app(AiSettings::class);
    $settings->manual_chat_enabled = true;
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    $settings->save();
    $this->actingAs($this->regularUser);
    AdminAssistantAgent::fake();
    $resolver = Mockery::mock(new AiProviderResolver($settings));
    $resolver->shouldNotReceive('resolve');
    $this->app->instance(AiProviderResolver::class, $resolver);
    $session = ChatSession::create(['user_id' => $this->regularUser->id, 'title' => 'New chat']);
    $before = $session->fresh()->getAttributes();
    $path = '/api/chats'.str_replace('{session}', $session->uuid, $suffix);

    $this->json($method, $path, ['message' => 'Blocked prompt'])->assertForbidden();

    expect(ChatSession::count())->toBe(1)
        ->and(ChatMessage::count())->toBe(0)
        ->and($session->fresh()->getAttributes())->toBe($before);
    AdminAssistantAgent::assertNeverPrompted();
})->with([
    'list' => ['GET', ''],
    'create' => ['POST', ''],
    'search' => ['GET', '/search?q=New'],
    'empty search' => ['GET', '/search'],
    'show' => ['GET', '/{session}'],
    'messages' => ['GET', '/{session}/messages'],
    'send' => ['POST', '/{session}/messages'],
    'stream' => ['POST', '/{session}/stream'],
    'delete' => ['DELETE', '/{session}'],
]);

test('admins cannot read or mutate another owners chat', function (string $method, string $suffix): void {
    $settings = app(AiSettings::class);
    $settings->manual_chat_enabled = true;
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    $settings->save();
    $this->actingAs($this->admin);
    AdminAssistantAgent::fake();
    $resolver = Mockery::mock(new AiProviderResolver($settings));
    $resolver->shouldNotReceive('resolve');
    $this->app->instance(AiProviderResolver::class, $resolver);
    $session = ChatSession::create(['user_id' => $this->regularUser->id, 'title' => 'New chat']);
    $before = $session->fresh()->getAttributes();

    $this->json($method, "/api/chats/{$session->uuid}{$suffix}", ['message' => 'Blocked prompt'])->assertForbidden();

    expect(ChatSession::count())->toBe(1)
        ->and(ChatMessage::count())->toBe(0)
        ->and($session->fresh()->getAttributes())->toBe($before);
    AdminAssistantAgent::assertNeverPrompted();
})->with([
    'show' => ['GET', ''],
    'messages' => ['GET', '/messages'],
    'send' => ['POST', '/messages'],
    'stream' => ['POST', '/stream'],
    'delete' => ['DELETE', ''],
]);

test('legacy echo history remains readable while manual chat is off', function (string $role): void {
    $user = User::factory()->create()->assignRole($role);
    $this->actingAs($user);
    $session = ChatSession::create(['user_id' => $user->id, 'title' => 'Legacy history', 'source' => 'echo']);
    $session->messages()->create(['role' => 'assistant', 'content' => 'Saved response']);
    $foreign = ChatSession::create(['user_id' => $this->regularUser->id, 'title' => 'Legacy history', 'source' => 'echo']);
    AdminAssistantAgent::fake();
    $resolver = Mockery::mock(new AiProviderResolver(app(AiSettings::class)));
    $resolver->shouldNotReceive('resolve');
    $this->app->instance(AiProviderResolver::class, $resolver);

    $this->getJson('/api/chats')->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $session->id);
    $this->getJson('/api/chats/search?q=Legacy')->assertSuccessful()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $session->id);
    $this->getJson("/api/chats/{$session->uuid}")->assertSuccessful()->assertJsonPath('data.id', $session->id);
    $this->getJson("/api/chats/{$session->uuid}/messages")->assertSuccessful()->assertJsonPath('data.0.content', 'Saved response');
    $this->getJson("/api/chats/{$foreign->uuid}")->assertForbidden();
    AdminAssistantAgent::assertNeverPrompted();
})->with(['admin', 'super_admin']);

test('enabled admin can send a prompt with configured timeout', function (): void {
    $user = User::factory()->create()->assignRole('admin');
    $this->actingAs($user);
    $settings = app(AiSettings::class);
    $settings->manual_chat_enabled = true;
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    $settings->timeout_seconds = 9;
    $settings->save();
    $session = ChatSession::create(['user_id' => $user->id, 'title' => 'New chat']);
    AdminAssistantAgent::fake(['ARC response']);

    $this->postJson("/api/chats/{$session->uuid}/messages", ['message' => 'Hello ARC'])
        ->assertSuccessful()->assertJsonPath('data.content', 'ARC response');

    expect($session->messages()->count())->toBe(2)
        ->and($session->fresh()->title)->toBe('Hello ARC');
    AdminAssistantAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->timeout === 9);
});

test('provider failures return safe ARC errors and report server side', function (string $endpoint): void {
    $this->actingAs($this->admin);
    $settings = app(AiSettings::class);
    $settings->manual_chat_enabled = true;
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    $settings->save();
    $session = ChatSession::create(['user_id' => $this->admin->id]);
    $exception = new RuntimeException('sk-secret-provider-error');
    $resolver = Mockery::mock(new AiProviderResolver($settings));
    mockExpectation($resolver, 'resolve')->once()->andThrow($exception);
    $this->app->instance(AiProviderResolver::class, $resolver);
    mockExpectation($this->mock(ExceptionHandler::class), 'report')->once()->with($exception);

    $response = $this->postJson("/api/chats/{$session->uuid}/{$endpoint}", ['message' => 'Hello ARC']);

    if ($endpoint === 'messages') {
        $response->assertStatus(502)->assertJsonPath('message', AiSseResponse::ERROR_MESSAGE);
        $content = $response->baseResponse->getContent();
    } else {
        $response->assertSuccessful();
        $content = $response->streamedContent();
        expect($content)->toContain('data: [DONE]');
    }

    expect($content)->toContain(AiSseResponse::ERROR_MESSAGE)->not->toContain('sk-secret-provider-error');
})->with(['messages', 'stream']);

test('deferred stream failures hide exception details and report server side', function (): void {
    $exception = new RuntimeException('sk-secret-stream-error');
    mockExpectation($this->mock(ExceptionHandler::class), 'report')->once()->with($exception);
    $events = (function () use ($exception): Generator {
        yield 'Partial ARC response';

        throw $exception;
    })();
    $response = TestResponse::fromBaseResponse(AiSseResponse::from($events));

    expect($response->streamedContent())
        ->toContain('Partial ARC response', AiSseResponse::ERROR_MESSAGE, 'data: [DONE]')
        ->not->toContain('sk-secret-stream-error');
});

test('openai provider toProviderConfig sets standard openai base url and defaults', function (): void {
    $provider = new AiProvider([
        'name' => 'OpenAI Production',
        'driver' => 'openai',
        'model' => 'gpt-4o-mini',
        'api_key' => 'sk-test-secret-key',
        'is_active' => true,
    ]);

    $config = $provider->toProviderConfig();

    expect($config['driver'])->toBe('openai')
        ->and($config['url'])->toBe('https://api.openai.com/v1')
        ->and($config['models']['text']['default'])->toBe('gpt-4o-mini')
        ->and($config['key'])->toBe('sk-test-secret-key')
        ->and($provider->isUsable())->toBeTrue();
});

test('openai provider connection test queries models endpoint with bearer token', function (): void {
    Http::fake([
        'https://api.openai.com/v1/models' => Http::response([
            'data' => [
                ['id' => 'gpt-4o-mini'],
                ['id' => 'gpt-4o'],
            ],
        ], 200),
    ]);

    $provider = new AiProvider([
        'name' => 'OpenAI Direct',
        'driver' => 'openai',
        'model' => 'gpt-4o-mini',
        'api_key' => 'sk-test-key',
        'is_active' => true,
    ]);

    AiProviderConnectionTest::run($provider);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/models'
        && $request->hasHeader('Authorization', 'Bearer sk-test-key'));
});

test('settings preserve encrypted api keys and show saved indicators after refresh', function (): void {
    $this->actingAs($this->admin);
    filament()->setCurrentPanel('admin');

    Livewire::test(AiSettingsPage::class)
        ->fillForm([
            'openai_api_key' => 'sk-openai-persisted-secret',
            'openai_url' => 'https://openai.example.com/v1',
            'openai_model' => 'gpt-4o',
            'openai_is_default' => false,
            'openai_compatible_providers' => [
                [
                    'name' => 'koamishin',
                    'model' => 'agy/claude-opus-4-6-thinking',
                    'url' => 'https://gateway.example.com/v1',
                    'api_key' => 'sk-compatible-persisted-secret',
                    'is_default' => true,
                    'headers' => [
                        ['name' => 'X-Custom-Auth', 'value' => 'secret-header'],
                    ],
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    // Verify database stored the records properly
    $openAiInDb = AiProvider::where('driver', 'openai')->first();
    expect($openAiInDb)->not->toBeNull()
        ->and($openAiInDb->api_key)->toBe('sk-openai-persisted-secret')
        ->and($openAiInDb->model)->toBe('gpt-4o');

    $compatibleInDb = AiProvider::where('driver', 'openai-compatible')->where('name', 'koamishin')->first();
    expect($compatibleInDb)->not->toBeNull()
        ->and($compatibleInDb->api_key)->toBe('sk-compatible-persisted-secret')
        ->and($compatibleInDb->model)->toBe('agy/claude-opus-4-6-thinking')
        ->and($compatibleInDb->base_url)->toBe('https://gateway.example.com/v1')
        ->and($compatibleInDb->is_default)->toBeTrue()
        ->and($compatibleInDb->headers)->toMatchArray(['X-Custom-Auth' => 'secret-header']);

    // Now re-mount the settings page (simulating page reload/refresh)
    $refreshed = Livewire::test(AiSettingsPage::class);
    $data = $refreshed->get('data');
    $firstCompatible = collect($data['openai_compatible_providers'] ?? [])->first();

    expect($data['openai_api_key'])->toBeNull()
        ->and($data['openai_url'])->toBe('https://openai.example.com/v1')
        ->and($firstCompatible)->not->toBeNull()
        ->and($firstCompatible['name'])->toBe('koamishin')
        ->and($firstCompatible['api_key'])->toBeNull()
        ->and($firstCompatible['is_default'])->toBeTrue();

    $refreshed
        ->assertSee('••••••••')
        ->assertSee('API key saved (encrypted). Leave blank to keep it; enter a new key to replace it.')
        ->assertDontSee('sk-openai-persisted-secret')
        ->assertDontSee('sk-compatible-persisted-secret')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($openAiInDb->fresh()->api_key)->toBe('sk-openai-persisted-secret')
        ->and($compatibleInDb->fresh()->api_key)->toBe('sk-compatible-persisted-secret')
        ->and($openAiInDb->fresh()->base_url)->toBe('https://openai.example.com/v1')
        ->and($compatibleInDb->fresh()->getRawOriginal('api_key'))->not->toContain('sk-compatible-persisted-secret');

    // Verify resolver picks up koamishin as the active provider
    $resolver = app(AiProviderResolver::class);
    $activeProvider = $resolver->adminProvider();
    expect($activeProvider)->not->toBeNull()
        ->and($activeProvider->name)->toBe('koamishin')
        ->and($activeProvider->model)->toBe('agy/claude-opus-4-6-thinking');
});
