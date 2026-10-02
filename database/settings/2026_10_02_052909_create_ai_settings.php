<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Whether advisory model calls may run at all. Off by default because
        // the deterministic policy engine is authoritative and must work with
        // no provider configured.
        $this->migrator->add('ai.advisory_enabled', false);

        // Ask the operator to opt in to sending anything to a third party.
        $this->migrator->add('ai.disclosure_accepted', false);

        // Seconds before a provider call is abandoned. Kept short because every
        // advisory path is best-effort and must never delay a payment.
        $this->migrator->add('ai.timeout_seconds', 20);

        // Whether the AI may propose disbursements, or only annotate them.
        // See .ai/rules/ai-agents.md: the default is advisory-only.
        $this->migrator->add('ai.allow_settlement_proposals', false);

        // Optional failover target used when the default provider rate-limits.
        $this->migrator->add('ai.failover_provider', null);
    }
};
