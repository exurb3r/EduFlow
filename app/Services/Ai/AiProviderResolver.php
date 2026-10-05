<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiProvider;
use App\Settings\AiSettings;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Providers\Provider;
use Throwable;

/**
 * Decides which provider an agent should use, and builds it.
 *
 * Precedence, highest first:
 *
 *   1. An explicit name passed by the caller.
 *   2. The provider flagged default in the admin panel.
 *   3. `AI_PROVIDER` from .env, for deployments that prefer config.
 *
 * An admin-configured provider is returned as a built Provider instance rather
 * than its driver name. That distinction matters: passing the bare name makes
 * the SDK resolve `config/ai.php`, which has no default text model, so an
 * openai-compatible endpoint fails with "requires a default text model". The
 * instance carries the model, key and headers that were saved in the panel.
 *
 * Every lookup is wrapped. This runs inside request handling, and a settings or
 * database problem must surface as "no provider" rather than an exception in
 * the settlement path. Callers treat null as fail-closed.
 */
final class AiProviderResolver
{
    /** @var array<string, Provider> */
    private array $built = [];

    public function __construct(private readonly AiSettings $settings) {}

    /**
     * The provider argument to hand to `prompt(provider: ...)`.
     *
     * @return array<int, Provider>|string|null
     */
    public function resolve(?string $explicit = null): array|string|null
    {
        $name = $explicit ?? $this->configuredName();

        if ($name === null) {
            return null;
        }

        if ($this->adminProvider()?->providerKey() !== $name) {
            // Not the admin provider: a plain name resolves against config/ai.php,
            // which is exactly what a .env deployment wants.
            return $name;
        }

        $provider = $this->buildFromSettings($name);

        return $provider instanceof Provider ? [$provider] : null;
    }

    /**
     * Build the provider instance for an admin-configured provider.
     *
     * Used when an agent needs a concrete Provider, for example to implement
     * the SDK's own `provider()` method.
     */
    public function buildFromSettings(?string $explicit = null): ?Provider
    {
        $name = $explicit ?? $this->configuredName();

        if ($name === null) {
            return null;
        }

        if (isset($this->built[$name])) {
            return $this->built[$name];
        }

        $config = $this->configFor($name);

        try {
            return $this->built[$name] = Ai::build($config);
        } catch (Throwable $e) {
            // Ai::build() refuses a name that collides with a built-in provider.
            report($e);

            return null;
        }
    }

    /**
     * Which provider is in force, for display in the admin and diagnostics.
     *
     * @return array{source: string, name: ?string, usable: bool, advisory_enabled: bool, provider: ?AiProvider}
     */
    public function describe(): array
    {
        $provider = $this->adminProvider();

        return [
            'source' => $provider instanceof AiProvider ? 'admin' : 'env',
            'name' => $provider?->providerKey() ?? config('ai.default'),
            'usable' => $provider instanceof AiProvider ? $provider->isUsable() : $this->settings->advisory_enabled,
            'advisory_enabled' => $this->settings->advisory_enabled,
            'provider' => $provider,
        ];
    }

    /**
     * The active admin-configured provider, if there is a usable one.
     */
    public function adminProvider(): ?AiProvider
    {
        [$provider] = AiProvider::active();

        return $provider;
    }

    /**
     * The provider name to use, or null when nothing is configured.
     */
    private function configuredName(): ?string
    {
        $provider = $this->adminProvider();

        if ($provider instanceof AiProvider) {
            return $provider->providerKey();
        }

        $env = config('ai.default');

        return filled($env) ? (string) $env : null;
    }

    /**
     * The config array for a provider name.
     *
     * @return array<string, mixed>
     */
    private function configFor(string $name): array
    {
        $provider = $this->adminProvider();

        if ($provider instanceof AiProvider && $provider->providerKey() === $name) {
            return $provider->toProviderConfig();
        }

        return (array) config("ai.providers.{$name}", ['driver' => $name]);
    }

    /**
     * The built-in driver names, for validation in the admin form.
     *
     * @return list<string>
     */
    public static function knownDrivers(): array
    {
        return array_map(fn (Lab $lab): string => $lab->value, Lab::cases());
    }
}
