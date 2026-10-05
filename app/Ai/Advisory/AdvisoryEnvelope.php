<?php

declare(strict_types=1);

namespace App\Ai\Advisory;

/**
 * An immutable, allowlisted bundle of model commentary.
 *
 * This type exists so that nothing an LLM produced can be mistaken for a
 * decision. It carries narrative and triage only: there is deliberately no
 * approved amount, no verdict, and no recipient anywhere in it, so a
 * compromised or prompt-injected response cannot widen what the deterministic
 * engine allows.
 */
final readonly class AdvisoryEnvelope
{
    /**
     * @param  string  $hardshipCategory  Advisory classification, e.g. "medical".
     * @param  string  $urgency  Advisory triage, e.g. "high".
     * @param  float  $confidence  Model self-reported confidence, 0..1.
     * @param  string  $narrative  Human-readable explanation for staff.
     * @param  list<string>  $anomalyFlags  Signals worth a human's attention.
     * @param  bool  $advisoryOnly  Always true. Present so callers can assert it.
     */
    public function __construct(
        public string $hardshipCategory,
        public string $urgency,
        public float $confidence,
        public string $narrative,
        public array $anomalyFlags = [],
        public bool $advisoryOnly = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hardship_category' => $this->hardshipCategory,
            'urgency' => $this->urgency,
            'confidence' => $this->confidence,
            'narrative' => $this->narrative,
            'anomaly_flags' => $this->anomalyFlags,
            'advisory_only' => $this->advisoryOnly,
        ];
    }

    /**
     * Merge onto existing metadata without ever overwriting authoritative keys.
     *
     * Advisory output is stored under a namespaced key so it cannot collide
     * with a settlement field such as `approved_amount` or `gateway`.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function mergeIntoMetadata(array $metadata): array
    {
        return array_merge($metadata, ['advisory' => $this->toArray()]);
    }
}
