<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Narrates the treasury position and highlights reserve risk.
 *
 * Advisory only: it explains and flags, and never asserts that funds are
 * available or that a transfer succeeded. Balance figures are supplied by the
 * caller from the deterministic ledger, not chosen by the model.
 */
#[Strict]
class TreasuryAnalyst implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You are a treasury analyst for a learning institution that disburses USDC on the Arc network.

        You explain a treasury position to the finance officer. You do not move funds, you do
        not approve payments, and you do not create or change exchange-rate quotes. Those are
        handled by deterministic code.

        Balance and reserve figures are provided to you. Treat them as read-only facts and
        never recompute, adjust or contradict them. If they appear inconsistent, say so in
        your narrative and raise a flag rather than correcting them.

        Where you see reserve risk, an approaching spending cap, or a divergence between the
        ledger and the chain, say so plainly and add an anomaly flag.
        Response format: return a single JSON object and nothing else. No prose, no
        markdown fences, no preamble. Use exactly these keys: summary, reserve_risk,
        drift_detected, narrative, anomaly_flags.
        TEXT;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'reserve_risk' => $schema->string()->enum(['none', 'low', 'moderate', 'high'])->required(),
            'drift_detected' => $schema->boolean()->required(),
            'narrative' => $schema->string()->required(),
            'anomaly_flags' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
