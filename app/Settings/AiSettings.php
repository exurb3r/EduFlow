<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Global AI behaviour, as configured from the admin panel.
 *
 * Provider endpoints and keys live in the `ai_providers` table rather than
 * here, because there can be several of them and each carries its own
 * encrypted key. This class only holds the switches.
 *
 * No API key is stored in this class: settings payloads are plain JSON in the
 * database, whereas provider keys use the model's `encrypted` cast.
 */
class AiSettings extends Settings
{
    /**
     * Whether advisory model calls may run at all.
     *
     * Off by default. The deterministic policy engine is authoritative and the
     * application must work with no provider configured, so this is an explicit
     * opt-in rather than "on unless configured".
     */
    public bool $advisory_enabled = false;

    /**
     * Whether the operator has acknowledged that advisory calls send student
     * data to a third-party provider. A second gate, so enabling AI is always
     * two deliberate actions.
     */
    public bool $disclosure_accepted = false;

    /**
     * Seconds before a provider call is abandoned.
     */
    public int $timeout_seconds = 20;

    /**
     * Whether the AI may propose disbursements through Approvable tools.
     *
     * Off by default: annotate only. See .ai/rules/ai-agents.md.
     */
    public bool $allow_settlement_proposals = false;

    /**
     * Optional provider name to fall back to when the default rate-limits.
     */
    public ?string $failover_provider = null;

    public static function group(): string
    {
        return 'ai';
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'advisory_enabled' => false,
            'disclosure_accepted' => false,
            'timeout_seconds' => 20,
            'allow_settlement_proposals' => false,
            'failover_provider' => null,
        ];
    }

    /**
     * Both gates must be on before any student data leaves the application.
     */
    public function mayCallProvider(): bool
    {
        return $this->advisory_enabled && $this->disclosure_accepted;
    }

    /**
     * Whether the AI is permitted to reach the payment path at all.
     */
    public function mayProposeSettlements(): bool
    {
        return $this->mayCallProvider() && $this->allow_settlement_proposals;
    }
}
