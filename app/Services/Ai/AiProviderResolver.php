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
 * Every lookup is wrapped: this runs inside request handling, and a settings or
 * database problem must surface as "no provider" rather than an exception in
 * the settlement path. Callers treat null as fail-closed and fall back to the
 * deterministic engine.
 */
final class AiProviderResolver
{
    /** @var array<string, Provider> */
    private array $built = [];

    public function __construct(private readonly AiSettings $settings) {}

    /**
     * The provider spec to hand to `prompt(provider: ...)`, or null.
     *
     * @return array<int, string>|string|null
     */
    public function resolve(?string $explicit = null): array|string|null
    {
        $name = $explicit ?? $this->configuredName();

        return $name;
    }

    /**
     * Build the provider instance for the admin-configured default.
     *
     * Used when an agent needs a concrete Provider rather than a name, for
     * example to implement the SDK's `provider()` method.
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

        try {
            $provider = Ai::build($this->configFor($name));
        } catch (Throwable $e) {
            // Ai::build() throws when the name collides with a built-in
            // provider. Fall back to referring to it by name instead.
            report($e);

            return null;
        }

        return $this->built[$name] = $provider;
    }

    /**
     * Which provider is in force, for display in the admin and diagnostics.
     */
    public function describe(): array
    {
        [$provider, $usable] = AiProvider::active();

        return [
            'source' => $provider !== null ? 'admin' : 'env',
            'name' => $provider?->providerKey() ?? config('ai.default'),
            'usable' => $provider !== null ? $usable : $this->settings->advisory_enabled,
            'advisory_enabled' => $this->settings->advisory_enabled,
            'provider' => $provider,
        ];
    }

    /**
     * The provider name to use, or null when nothing is configured.
     */
    private function configuredName(): ?string
    {
        [$provider] = AiProvider::active();

        if ($provider !== null) {
            return $provider->providerKey();
        }

        $env = config('ai.default');

        return filled($env) ? (string) $env : null;
    }

    /**
     * The config array for a provider name.
     *
     * For an admin-configured row this comes from the database; otherwise it is
     * whatever is already in config/ai.php, which keeps .env deployments
     * working exactly as the SDK intends.
     *
     * @return array<string, mixed>
     */
    private function configFor(string $name): array
    {
        [$provider] = AiProvider::active();

        if ($provider !== null && $provider->providerKey() === $name) {
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
