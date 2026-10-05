<?php

declare(strict_types=1);

use App\Ai\Advisory\AdvisoryGate;
use App\Models\AiProvider;
use App\Services\Ai\AiProviderResolver;
use App\Settings\AiSettings;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Admin-configured AI providers.
 *
 * Two things are load-bearing here and both are asserted against the raw
 * database rather than through the model: the key must be ciphertext at rest,
 * and a stored key must never be handed back to a form.
 *
 * RefreshDatabase already applies to every Feature test, so each case starts
 * from an empty ai_providers table.
 */
function makeProvider(array $attributes = []): AiProvider
{
    return AiProvider::create(array_merge([
        'name' => 'Local Ollama',
        'driver' => 'openai-compatible',
        'base_url' => 'http://127.0.0.1:11434/v1',
        'model' => 'llama3.1',
        'api_key' => 'sk-plaintext-should-never-persist',
        'is_active' => true,
        'is_default' => true,
    ], $attributes));
}

it('stores the api key as ciphertext, never plaintext', function (): void {
    $provider = makeProvider();

    $raw = DB::table('ai_providers')->where('id', $provider->id)->value('api_key');

    expect((string) $raw)->not->toContain('plaintext-should-never-persist')
        // Laravel's encrypted cast produces a base64 JSON envelope.
        ->and((string) $raw)->toStartWith('eyJ')
        // But it still round-trips through the model.
        ->and($provider->fresh()->api_key)->toBe('sk-plaintext-should-never-persist');
});

it('cannot read the stored key without the application key', function (): void {
    $provider = makeProvider();

    $raw = (string) DB::table('ai_providers')->where('id', $provider->id)->value('api_key');

    // The payload is a JSON envelope whose `value` is base64 ciphertext.
    $envelope = json_decode(base64_decode($raw, true), true);
    expect($envelope)->toBeArray()->toHaveKeys(['iv', 'value', 'mac']);

    // Decrypting with the wrong key must fail, which is what stops a leaked
    // database dump from being readable without APP_KEY.
    expect(fn () => Crypt::decryptString($envelope['value'], false, random_bytes(16)))
        ->toThrow(DecryptException::class);
});

it('asks for JSON by default so a streaming gateway cannot break the response', function (): void {
    // 9Router streams SSE regardless of `stream: false`, which the SDK cannot
    // parse. The Accept header must be set even when the operator added none.
    $config = makeProvider()->toProviderConfig();

    expect($config['headers'])->toHaveKey('Accept')
        ->and($config['headers']['Accept'])->toBe('application/json');
});

it('does not override an Accept header the operator set explicitly', function (): void {
    $config = makeProvider(['headers' => ['Accept' => 'text/plain']])->toProviderConfig();

    expect($config['headers']['Accept'])->toBe('text/plain');
});

it('builds an openai-compatible provider config with url, key and model', function (): void {
    $config = makeProvider([
        'headers' => ['X-Tenant-Id' => 'acme'],
    ])->toProviderConfig();

    expect($config['driver'])->toBe('openai-compatible')
        ->and($config['url'])->toBe('http://127.0.0.1:11434/v1')
        ->and($config['key'])->toBe('sk-plaintext-should-never-persist')
        // The Accept default is merged in, and the operator's own header is kept.
        ->and($config['headers'])->toBe([
            'Accept' => 'application/json',
            'X-Tenant-Id' => 'acme',
        ])
        ->and($config['models']['text']['default'])->toBe('llama3.1');
});

it('treats a provider with no base url as unusable for openai-compatible', function (): void {
    expect(makeProvider(['base_url' => null])->isUsable())->toBeFalse()
        ->and(makeProvider(['is_active' => false])->isUsable())->toBeFalse()
        ->and(makeProvider(['model' => null])->isUsable())->toBeFalse()
        ->and(makeProvider()->isUsable())->toBeTrue();
});

it('accepts an openai-compatible provider with no key at all', function (): void {
    // Local endpoints usually need no bearer token, so a missing key must not
    // make the provider unusable.
    expect(makeProvider(['api_key' => null])->isUsable())->toBeTrue();
});

it('prefers the default provider, then falls back by sort order', function (): void {
    AiProvider::query()->delete();

    $second = makeProvider(['name' => 'Second', 'is_default' => false, 'sort_order' => 2]);
    $first = makeProvider(['name' => 'First', 'is_default' => false, 'sort_order' => 1]);

    expect(AiProvider::active()[0]->id)->toBe($first->id);

    $second->update(['is_default' => true]);

    expect(AiProvider::active()[0]->id)->toBe($second->id);
});

it('ignores inactive providers when resolving', function (): void {
    AiProvider::query()->delete();
    makeProvider(['name' => 'Disabled', 'is_active' => false, 'is_default' => true]);

    [$provider, $usable] = AiProvider::active();

    expect($provider)->toBeNull()
        ->and($usable)->toBeFalse();
});

it('requires both switches before any provider call is allowed', function (): void {
    $settings = app(AiSettings::class);

    expect($settings->mayCallProvider())->toBeFalse();

    $settings->advisory_enabled = true;
    expect($settings->mayCallProvider())->toBeFalse('the disclosure gate still applies');

    $settings->disclosure_accepted = true;
    expect($settings->mayCallProvider())->toBeTrue();
});

it('keeps settlement proposals off unless explicitly allowed', function (): void {
    $settings = app(AiSettings::class);

    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;

    expect($settings->mayProposeSettlements())->toBeFalse();

    $settings->allow_settlement_proposals = true;

    expect($settings->mayProposeSettlements())->toBeTrue();
});

it('returns no advisory and contacts nobody when the switches are off', function (): void {
    $settings = app(AiSettings::class);

    expect($settings->mayCallProvider())->toBeFalse();

    // Even with a provider configured, the switches win: no call is attempted,
    // so a misconfigured endpoint cannot produce a request.
    makeProvider();

    expect(app(AdvisoryGate::class)->assessHardship(['reason' => 'Emergency clinic visit']))
        ->toBeNull();
});

it('reports which provider is in force for the admin display', function (): void {
    AiProvider::query()->delete();
    makeProvider();

    $description = app(AiProviderResolver::class)->describe();

    expect($description['source'])->toBe('admin')
        ->and($description['name'])->toBe('openai-compatible')
        ->and($description['usable'])->toBeTrue();
});

it('falls back to the env configured provider when no admin row exists', function (): void {
    AiProvider::query()->delete();

    config(['ai.default' => 'anthropic']);

    $description = app(AiProviderResolver::class)->describe();

    expect($description['source'])->toBe('env')
        ->and($description['name'])->toBe('anthropic');
});
