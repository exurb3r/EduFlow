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
     * The complete set of keys a bill classification may carry.
     *
     * The same rule as `ALLOWED_KEYS` applied to the second advisory shape:
     * a key that is not named here is discarded rather than merged, so an
     * injected `"amount_base_units"`, `"approved"` or `"recipient"` never
     * reaches a caller. Note that `beneficiary_alias` is a human-readable hint
     * and not an address field — the DTO has nowhere to put one.
     *
     * @var list<string>
     */
    public const CLASSIFICATION_KEYS = [
        'category',
        'beneficiary_alias',
        'confidence',
        'narrative',
        'anomaly_flags',
    ];

    /**
     * Bill categories the classifier may name.
     *
     * Written out literally rather than read from `StudentBillCategory` so the
     * advisory layer cannot be broken by a rename in the domain enum. An
     * unrecognised category falls back to `miscellaneous`, which is the
     * allowlist's own "none of these" value and is therefore inside the set the
     * system already accepts — see `normalizeClassificationCategory()` for why
     * that is the right failure here.
     *
     * @var list<string>
     */
    public const ALLOWED_BILL_CATEGORIES = [
        'miscellaneous',
        'laboratory',
        'registration',
        'examination',
    ];

    /**
     * The value an unrecognised category falls back to.
     */
    public const FALLBACK_BILL_CATEGORY = 'miscellaneous';

    private const MAX_BENEFICIARY_ALIAS_LENGTH = 200;

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
     * Turn raw bill-classification output into an AdvisoryEnvelope, or refuse it.
     *
     * The second half of the boundary, applying the same rules the hardship
     * path already enforces. Every difference from `sanitize()` is deliberate
     * and documented at the point it is made:
     *
     *  - an unrecognised category falls back to `miscellaneous` rather than
     *    voiding the whole advisory (see `normalizeClassificationCategory()`);
     *  - an out-of-range confidence is clamped rather than refused, because it
     *    is a self-reported hint with no authority over anything;
     *  - a beneficiary alias that looks like an address voids the *entire*
     *    advisory, not just the field, because a model that has produced a
     *    payout destination has been tampered with and its remaining output
     *    should not be presented as trusted either.
     *
     * @param  array<string, mixed>  $payload
     */
    public function sanitizeClassification(array $payload): ?AdvisoryEnvelope
    {
        // Same boundary as above: intersect against the allowlist rather than
        // filtering afterwards, so `amount_base_units`, `approved_amount`,
        // `recipient` and `decision` are discarded and never merged.
        $clean = array_intersect_key($payload, array_flip(self::CLASSIFICATION_KEYS));

        // A missing key means the response does not match the contract.
        foreach (self::CLASSIFICATION_KEYS as $key) {
            if (! array_key_exists($key, $clean)) {
                return null;
            }
        }

        $category = $this->normalizeClassificationCategory($clean['category']);

        if ($category === null) {
            return null;
        }

        $alias = $this->sanitizeClassificationAlias($clean['beneficiary_alias']);

        if ($alias === false) {
            return null;
        }

        $narrative = $clean['narrative'];

        if ($narrative !== null && ! is_string($narrative)) {
            return null;
        }

        $flags = $clean['anomaly_flags'];

        if (! is_array($flags)) {
            return null;
        }

        $confidence = $this->classificationConfidence($clean['confidence']);

        if ($confidence === null) {
            return null;
        }

        return AdvisoryEnvelope::forBillClassification(
            category: $category,
            confidence: $confidence,
            beneficiaryAlias: $alias,
            narrative: $narrative === null ? null : $this->emptyToNull($narrative),
            anomalyFlags: $this->sanitizeFlags($flags),
        );
    }

    /**
     * Match a category against the allowlist, falling back rather than failing.
     *
     * A category is a label, not a control: none of the values grants any
     * authority, so the worst a wrong one can do is mislabel a row a clerk is
     * reading anyway. Refusing the whole advisory over it would throw away the
     * anomaly flags with it — and those are the part a human actually needs
     * when a model invents a category outside the vocabulary. Falling back to
     * `miscellaneous` also introduces no new information, because it is already
     * a member of the closed set, and the honest confidence is preserved
     * alongside it so a caller can see the model was unsure.
     *
     * Only strings are downgraded this way. A category of the wrong type is a
     * shape violation and is refused outright, exactly as the hardship path
     * refuses a category that is a list.
     */
    private function normalizeClassificationCategory(mixed $category): ?string
    {
        if (! is_string($category)) {
            return null;
        }

        $category = mb_strtolower(trim($category));

        return in_array($category, self::ALLOWED_BILL_CATEGORIES, true)
            ? $category
            : self::FALLBACK_BILL_CATEGORY;
    }

    /**
     * Clamp a confidence into 0..1, or refuse a value that is not a number.
     *
     * Clamping rather than refusing differs from the hardship path on purpose.
     * Confidence is the model's opinion of its own accuracy and reaches nothing
     * financial, so a model that answers 1.8 has misreported a hint, not
     * attempted a control. Non-numeric input is still refused: "quite sure" or
     * `true` is not a confidence at all, and coercing it would invent precision.
     */
    private function classificationConfidence(mixed $confidence): ?float
    {
        if (is_string($confidence)) {
            $confidence = trim($confidence);

            // A gateway that ignores `response_format` quotes every value.
            if (isset(self::CONFIDENCE_WORDS[strtolower($confidence)])) {
                $confidence = self::CONFIDENCE_WORDS[strtolower($confidence)];
            } elseif (is_numeric($confidence)) {
                $confidence = (float) $confidence;
            }
        }

        if (! is_int($confidence) && ! is_float($confidence)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $confidence));
    }

    /**
     * Cap an alias and refuse anything that looks like a payout destination.
     *
     * Three outcomes, distinguished so the caller cannot mistake them: the
     * alias itself, `null` for "no alias given", or `false` to void the whole
     * advisory. A non-string alias is `false` rather than `null` because it is a
     * shape violation, not an absent answer.
     *
     * The address test matches `0x` plus 40 hex characters anywhere in the
     * value rather than only as the whole string, because the interesting attack
     * is "pay 0x…" inside an otherwise innocent label, and because an alias that
     * quotes a chain address is doing the one job this field must never do:
     * naming where money goes. An EIP-55 checksum casing is irrelevant — Circle
     * only requires 40 hex characters — so the lenient pattern is the right one
     * to reject on.
     *
     * The length cap runs first, which is the safe order: a destination buried
     * past the 200th character is truncated away rather than stored.
     */
    private function sanitizeClassificationAlias(mixed $alias): string|null|false
    {
        if ($alias === null) {
            return null;
        }

        if (! is_string($alias)) {
            return false;
        }

        $alias = mb_substr(trim($alias), 0, self::MAX_BENEFICIARY_ALIAS_LENGTH);

        return preg_match('/0x[0-9a-fA-F]{40}/', $alias) === 1 ? false : $this->emptyToNull($alias);
    }

    /**
     * Blank free text is the absence of an answer, not an empty answer.
     *
     * Both narrative and alias are nullable in the classifier's schema, so a
     * gateway that sends `""` instead of `null` means the same thing and is
     * normalised to null rather than stored as a string that reads as advice.
     */
    private function emptyToNull(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
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
