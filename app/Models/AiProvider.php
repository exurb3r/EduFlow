<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Enums\Lab;

/**
 * An AI provider configured from the admin panel.
 *
 * Supports every driver the SDK exposes, including `openai-compatible` for any
 * endpoint that speaks the OpenAI wire format — Ollama, LM Studio, vLLM,
 * LiteLLM, Together, or a corporate gateway.
 *
 * `api_key` uses the framework's `encrypted` cast, so the column holds
 * ciphertext at rest. That also means the key is never searchable or sortable,
 * and a raw database dump leaks nothing.
 *
 * @property int $id
 * @property string $name
 * @property string $driver
 * @property string|null $base_url
 * @property string|null $model
 * @property string|null $api_key
 * @property array<string, string>|null $headers
 * @property bool $is_active
 * @property bool $is_default
 * @property int $sort_order
 */
class AiProvider extends Model
{
    protected $fillable = [
        'name',
        'driver',
        'base_url',
        'model',
        'api_key',
        'headers',
        'is_active',
        'is_default',
        'sort_order',
    ];

    /**
     * `encrypted` uses APP_KEY. It cannot be used in a where clause, which is
     * fine: nothing ever needs to query by key.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'headers' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Providers the SDK can build, for the admin select.
     *
     * A flat `value => label` map, which Filament accepts unambiguously.
     *
     * @return array<string, string>
     */
    public static function driverOptions(): array
    {
        return [
            'openai-compatible' => 'OpenAI-compatible (Ollama, LM Studio, vLLM, LiteLLM, gateway)',
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'gemini' => 'Google Gemini',
            'groq' => 'Groq',
            'xai' => 'xAI',
            'deepseek' => 'DeepSeek',
            'mistral' => 'Mistral',
            'ollama' => 'Ollama (native driver)',
            'openrouter' => 'OpenRouter',
            'azure' => 'Azure OpenAI',
        ];
    }

    /**
     * Whether this provider still has everything it needs to be called.
     */
    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->driver === 'openai-compatible' && blank($this->base_url)) {
            return false;
        }

        // A key is optional: local endpoints usually do not need one.
        return filled($this->model);
    }

    /**
     * Build the SDK provider for this row via the on-demand provider API.
     *
     * `Ai::build()` is the SDK's supported way to construct a provider from a
     * runtime array rather than config/ai.php, which is what makes an
     * admin-configured endpoint possible at all.
     *
     * @return array<string, mixed>
     */
    public function toProviderConfig(): array
    {
        $config = [
            'driver' => $this->driver,
        ];

        if (filled($this->base_url)) {
            $config['url'] = $this->base_url;
        }

        if (filled($this->api_key)) {
            $config['key'] = $this->api_key;
        }

        // Ask for JSON explicitly. Some OpenAI-compatible gateways stream SSE
        // regardless of `stream: false`, which the SDK cannot parse, and an
        // Accept header is the reliable way to prevent that. Set as a default so
        // an operator does not have to discover it.
        $headers = $this->headers ?? [];

        if (! isset($headers['Accept'])) {
            $headers = array_merge(['Accept' => 'application/json'], $headers);
        }

        if ($headers !== []) {
            $config['headers'] = $headers;
        }

        if (filled($this->model)) {
            $config['models'] = [
                'text' => ['default' => $this->model],
            ];
        }

        return $config;
    }

    /**
     * The provider name to pass to `prompt(provider: ...)`.
     */
    public function providerKey(): string
    {
        // Lab cases are the built-in names; anything else is an on-demand name.
        return Lab::tryFrom($this->driver)?->value ?? $this->driver;
    }

    /**
     * @param  Builder<AiProvider>  $query
     * @return Builder<AiProvider>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The active provider, preferring the one flagged default.
     *
     * @return array{AiProvider|null, bool} the provider and whether it is usable
     */
    public static function active(): array
    {
        $provider = static::query()
            ->usable()
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        return [$provider, $provider instanceof self && $provider->isUsable()];
    }

    /**
     * Clear the default flag from every other provider.
     */
    public static function clearDefault(): void
    {
        static::query()->where('is_default', true)->update(['is_default' => false]);
    }
}
