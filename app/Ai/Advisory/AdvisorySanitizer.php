<?php

declare(strict_types=1);

namespace App\Ai\Advisory;

/**
 * Turns raw model output into an AdvisoryEnvelope, or refuses it.
 *
 * The schema is an allowlist, not a filter applied afterwards: a key that is
 * not named here is dropped rather than merged, so a prompt-injected
 * `"approved_amount": 999999` or `"decision": "auto_approve"` cannot reach the
 * policy engine. Anything missing, mistyped or out of range is rejected
 * outright, because a partially-trusted advisory is worse than none: the
 * caller then falls back to the deterministic engine alone.
 */
final class AdvisorySanitizer
{
    /**
     * The complete set of keys an advisory response may carry.
     *
     * Note what is absent: no amount, no verdict, no recipient, no wallet. If
     * a field is not here it is not advisory, and it is not accepted.
     *
     * @var list<string>
     */
    public const ALLOWED_KEYS = [
        'hardship_category',
        'urgency',
        'confidence',
        'narrative',
        'anomaly_flags',
    ];

    /** @var list<string> */
    public const ALLOWED_CATEGORIES = [
        'medical',
        'academic_materials',
        'tuition_shortfall',
        'living_costs',
        'other',
    ];

    /** @var list<string> */
    public const ALLOWED_URGENCIES = ['standard', 'high'];

    /**
     * Coarse confidence words mapped to the middle of their band.
     *
     * A model asked for `number 0..1` will often answer "high" instead,
     * particularly through a gateway that drops the response schema. Since
     * confidence is advisory-only and cannot influence an amount, accepting a
     * small fixed vocabulary is bounded and auditable in a way that accepting
     * arbitrary text would not. Anything outside this list is still rejected.
     *
     * @var array<string, float>
     */
    public const CONFIDENCE_WORDS = [
        'high' => 0.9,
        'medium' => 0.6,
        'moderate' => 0.6,
        'low' => 0.3,
    ];

    private const MAX_NARRATIVE_LENGTH = 2000;

    private const MAX_FLAGS = 10;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sanitize(array $payload): ?AdvisoryEnvelope
    {
        // Drop everything not explicitly allowed. This is the boundary.
        $clean = array_intersect_key($payload, array_flip(self::ALLOWED_KEYS));

        // A missing key means the response does not match the contract.
        foreach (self::ALLOWED_KEYS as $key) {
            if (! array_key_exists($key, $clean)) {
                return null;
            }
        }

        $category = $clean['hardship_category'];
        $urgency = $clean['urgency'];
        $narrative = $clean['narrative'];
        $flags = $clean['anomaly_flags'];

        if (! is_string($category) || ! in_array($category, self::ALLOWED_CATEGORIES, true)) {
            return null;
        }

        if (! is_string($urgency) || ! in_array($urgency, self::ALLOWED_URGENCIES, true)) {
            return null;
        }

        if (! is_string($narrative) || trim($narrative) === '') {
            return null;
        }

        if (! is_array($flags)) {
            return null;
        }

        // Confidence is advisory-only and cannot affect an amount, so a
        // numerically-formatted string is tolerated: gateways that ignore
        // `response_format` routinely quote every value. Anything that is not
        // numeric - "high", true, null, a list - is still rejected outright.
        $confidence = $clean['confidence'];

        if (is_string($confidence)) {
            $confidence = trim($confidence);

            if (isset(self::CONFIDENCE_WORDS[strtolower($confidence)])) {
                $confidence = self::CONFIDENCE_WORDS[strtolower($confidence)];
            } elseif (is_numeric($confidence)) {
                $confidence = (float) $confidence;
            }
        }

        if (! is_int($confidence) && ! is_float($confidence)) {
            return null;
        }

        $confidence = (float) $confidence;

        if ($confidence < 0.0 || $confidence > 1.0) {
            return null;
        }

        return new AdvisoryEnvelope(
            hardshipCategory: $category,
            urgency: $urgency,
            confidence: $confidence,
            narrative: mb_substr(trim($narrative), 0, self::MAX_NARRATIVE_LENGTH),
            anomalyFlags: $this->sanitizeFlags($flags),
        );
    }

    /**
     * @param  array<mixed>  $flags
     * @return list<string>
     */
    private function sanitizeFlags(array $flags): array
    {
        $clean = [];

        foreach ($flags as $flag) {
            if (! is_string($flag)) {
                continue;
            }

            $flag = mb_substr(trim($flag), 0, 200);

            if ($flag === '') {
                continue;
            }

            $clean[] = $flag;

            if (count($clean) >= self::MAX_FLAGS) {
                break;
            }
        }

        return $clean;
    }
}
