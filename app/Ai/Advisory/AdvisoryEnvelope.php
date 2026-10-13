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
 *
 * Two advisory shapes share it, distinguished by which fields are populated —
 * hardship triage (`hardshipCategory`/`urgency`) and bill classification
 * (`category`/`beneficiaryAlias`). Nothing money-shaped was added for the
 * second one. A classification carries a label, a human-readable beneficiary
 * hint, a confidence and free text; it has no amount field, no approval field
 * and no wallet address field, because a type that cannot hold one is a
 * stronger boundary than a type that filters one out.
 */
final readonly class AdvisoryEnvelope
{
    /**
     * @param  string|null  $hardshipCategory  Advisory classification, e.g. "medical". Null on a bill classification.
     * @param  string|null  $urgency  Advisory triage, e.g. "high". Null on a bill classification.
     * @param  float  $confidence  Model self-reported confidence, 0..1.
     * @param  string|null  $narrative  Human-readable explanation for staff.
     * @param  list<string>  $anomalyFlags  Signals worth a human's attention.
     * @param  bool  $advisoryOnly  Always true. Present so callers can assert it.
     * @param  string|null  $category  Bill classification, e.g. "laboratory". Null on hardship triage.
     * @param  string|null  $beneficiaryAlias  Plain-language hint naming who a bill appears to belong
     *                                         to. Never an address: the sanitizer refuses any alias
     *                                         that looks like one, so a model can never be the source
     *                                         of a payout destination.
     */
    public function __construct(
        public ?string $hardshipCategory,
        public ?string $urgency,
        public float $confidence,
        public ?string $narrative,
        public array $anomalyFlags = [],
        public bool $advisoryOnly = true,
        public ?string $category = null,
        public ?string $beneficiaryAlias = null,
    ) {}

    /**
     * Build a bill classification envelope.
     *
     * A factory rather than a bare constructor call so the two shapes cannot be
     * assembled by leaving out the fields that make each one trustworthy: the
     * hardship triage fields stay null here, and a category is always a value
     * the sanitizer has already matched against its allowlist.
     *
     * @param  list<string>  $anomalyFlags
     */
    public static function forBillClassification(
        string $category,
        float $confidence,
        ?string $beneficiaryAlias = null,
        ?string $narrative = null,
        array $anomalyFlags = [],
    ): self {
        return new self(
            hardshipCategory: null,
            urgency: null,
            confidence: $confidence,
            narrative: $narrative,
            anomalyFlags: $anomalyFlags,
            category: $category,
            beneficiaryAlias: $beneficiaryAlias,
        );
    }

    /**
     * Whether this envelope holds a bill classification rather than hardship triage.
     *
     * The discriminator is `category` because the sanitizer only ever sets it to
     * a member of a closed allowlist, so it is null on one shape and never null
     * on the other.
     */
    public function isBillClassification(): bool
    {
        return $this->category !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->isBillClassification()) {
            return [
                'category' => $this->category,
                'beneficiary_alias' => $this->beneficiaryAlias,
                'confidence' => $this->confidence,
                'narrative' => $this->narrative,
                'anomaly_flags' => $this->anomalyFlags,
                'advisory_only' => $this->advisoryOnly,
            ];
        }

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
