<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\BillPolicyContext;
use App\DTOs\BillPolicyDecision;
use App\Enums\AgentDecisionType;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use InvalidArgumentException;

/**
 * The built-in deterministic policy for student bill payments.
 *
 * This class **decides**. It answers one question -- pay this bill without a
 * human, hold it, send it to a person, or refuse it -- from plain values, with
 * no model, no query, no clock and no randomness. It is decidable with AI
 * entirely disabled, which is what PLAN.md §17.2 requires of the finance
 * decision path: no model runs in the finance decision path.
 *
 * ## The one-way door for advisory input
 *
 * An optional third-party classification may be supplied as an *input*
 * (check 1). It can only ever make the outcome more conservative, and that is
 * structural rather than a matter of discipline:
 *
 *  - It is read by exactly one check, and that check can only return ESCALATE.
 *  - The allow-list is matched against the bill's own recorded category
 *    ({@see BillPolicyContext::$category}). `advisoryCategory` is never
 *    consulted, so a classifier cannot grant a category to itself or to us.
 *  - Ceilings come from this class and from the institution's limits, both of
 *    which are plain integers. No advisory field holds an amount, so none can
 *    raise one.
 *  - Absent advisory is read as *unknown*, which escalates. There is no
 *    version of "the model had nothing to say" that produces an auto-approval.
 *
 * ## Check order
 *
 * The order is itself a safety property and must not be reshuffled:
 *
 *  0. `BILL_AMOUNT_NOT_POSITIVE_V1`           -> REJECT    (a non-payment is never payable)
 *  1. `ADVISORY_ANOMALY_FLAGS_V1`             -> ESCALATE  (uncertainty blocks automation)
 *     `ADVISORY_CLASSIFICATION_MISSING_V1`    -> ESCALATE
 *     `ADVISORY_CONFIDENCE_BELOW_FLOOR_V1`    -> ESCALATE
 *  2. `CATEGORY_NOT_ALLOWED_V1`               -> ESCALATE  (fixed-purpose charges only)
 *  3. `VENDOR_DESTINATION_UNVERIFIED_V1`      -> ESCALATE  (never invent a recipient)
 *  4. `TREASURY_RESERVE_BREACH_V1`            -> HOLD      (treasury safety outranks speed)
 *  5. `AUTO_PAY_CEILING_EXCEEDED_V1`          -> ESCALATE
 *  6. `DAILY_CAP_EXCEEDED_V1`                 -> ESCALATE
 *  7. `BILL_AUTO_PAYMENT_V1`                  -> AUTO_APPROVE (exact amount, no rounding)
 *
 * Anomaly flags are checked before the missing/unsure classification so the
 * recorded reason is the more specific one. Everything before the approval is a
 * stop; only reaching the last line moves money.
 *
 * ## Defaults, and why these numbers
 *
 * Every ceiling is configurable through the constructor's `$settings` array so
 * an operator can retune without editing this file. The defaults are chosen to
 * be small on purpose:
 *
 *  - `AUTO_PAY_CEILING_BASE_UNITS` = 25.00 USDC. An order of magnitude above
 *    routine consumable and examination fees, and below the size at which a
 *    single line item deserves a person's eyes. An unattended payment that
 *    turns out to be wrong is not practically recoverable, so the lane that
 *    omits the person is kept small.
 *  - `DAILY_CAP_BASE_UNITS` = 100.00 USDC across the whole lane per day. Even
 *    at the per-bill ceiling, a day's unattended outflow stays a figure a
 *    bursar can reconstruct from the day's queue without an audit.
 *  - `ADVISORY_CONFIDENCE_FLOOR` = 0.90. The advisory ranker reads 0.75 for
 *    "does this bill recur", where a wrong yes costs a supervisor a little
 *    reading time. Moving money is the opposite error class, so the bar for
 *    *enabling* automation is higher than the bar for ranking.
 *  - `ALLOWED_CATEGORIES` = the fixed-purpose fees the college itself levies
 *    (registration, examination, laboratory). `miscellaneous` is deliberately
 *    excluded: it is the catch-all a charge falls back to, so it is where a
 *    re-labelled charge hides.
 *
 * An institution may pass **tighter** limits in the context; the effective
 * ceiling is the lower of the two. It may never be more permissive.
 */
final readonly class BillPaymentPolicy
{
    /** Largest single unattended payment when nothing is configured. 25.00 USDC at 6 decimals. */
    public const int DEFAULT_AUTO_PAY_CEILING_BASE_UNITS = 25_000_000;

    /** Most the lane may spend unattended in one day. 100.00 USDC at 6 decimals. */
    public const int DEFAULT_DAILY_CAP_BASE_UNITS = 100_000_000;

    /** How sure a classification must be before it does not block automation. */
    public const float DEFAULT_ADVISORY_CONFIDENCE_FLOOR = 0.90;

    /** @var list<string> */
    public const array DEFAULT_ALLOWED_CATEGORIES = ['registration', 'examination', 'laboratory'];

    /** Stable policy codes. Safe to persist and to assert on. */
    public const string CODE_AMOUNT_NOT_POSITIVE = 'BILL_AMOUNT_NOT_POSITIVE_V1';

    public const string CODE_ADVISORY_ANOMALY_FLAGS = 'ADVISORY_ANOMALY_FLAGS_V1';

    public const string CODE_ADVISORY_MISSING = 'ADVISORY_CLASSIFICATION_MISSING_V1';

    public const string CODE_ADVISORY_CONFIDENCE = 'ADVISORY_CONFIDENCE_BELOW_FLOOR_V1';

    public const string CODE_CATEGORY_NOT_ALLOWED = 'CATEGORY_NOT_ALLOWED_V1';

    public const string CODE_DESTINATION_UNVERIFIED = 'VENDOR_DESTINATION_UNVERIFIED_V1';

    public const string CODE_RESERVE_BREACH = 'TREASURY_RESERVE_BREACH_V1';

    public const string CODE_CEILING_EXCEEDED = 'AUTO_PAY_CEILING_EXCEEDED_V1';

    public const string CODE_DAILY_CAP_EXCEEDED = 'DAILY_CAP_EXCEEDED_V1';

    public const string CODE_AUTO_PAYMENT = 'BILL_AUTO_PAYMENT_V1';

    /**
     * Resolved operator settings. Never read from the advisory input.
     *
     * @var array{advisory_confidence_floor: float, auto_pay_ceiling_base_units: int, daily_cap_base_units: int, allowed_categories: list<string>}
     */
    private array $settings;

    /**
     * Resolve the operator settings once, at construction.
     *
     * @param  array<string, mixed>  $settings  Supported keys: `advisory_confidence_floor`
     *                                          (float within (0, 1]), `auto_pay_ceiling_base_units`
     *                                          (positive int), `daily_cap_base_units` (positive int)
     *                                          and `allowed_categories` (list of labels).
     *                                          Anything absent keeps its conservative default;
     *                                          anything present but nonsensical is refused
     *                                          rather than silently coerced, because a misconfigured
     *                                          ceiling is indistinguishable from a deliberate one.
     */
    public function __construct(array $settings = [])
    {
        $floor = $settings['advisory_confidence_floor'] ?? self::DEFAULT_ADVISORY_CONFIDENCE_FLOOR;

        if ((! is_int($floor) && ! is_float($floor)) || $floor <= 0 || $floor > 1) {
            throw new InvalidArgumentException('advisory_confidence_floor must be a number within (0, 1].');
        }

        $categories = $settings['allowed_categories'] ?? self::DEFAULT_ALLOWED_CATEGORIES;

        if (! is_array($categories)) {
            throw new InvalidArgumentException('allowed_categories must be a list of category labels.');
        }

        $this->settings = [
            'advisory_confidence_floor' => (float) $floor,
            'auto_pay_ceiling_base_units' => $this->positiveUnits($settings['auto_pay_ceiling_base_units'] ?? self::DEFAULT_AUTO_PAY_CEILING_BASE_UNITS, 'auto_pay_ceiling_base_units'),
            'daily_cap_base_units' => $this->positiveUnits($settings['daily_cap_base_units'] ?? self::DEFAULT_DAILY_CAP_BASE_UNITS, 'daily_cap_base_units'),
            'allowed_categories' => array_values(array_map($this->label(...), $categories)),
        ];
    }

    /**
     * Decide what happens to one bill payment.
     *
     * Pure: the same context always yields the same decision, and nothing is
     * read, written or emitted along the way.
     */
    public function evaluate(BillPolicyContext $context): BillPolicyDecision
    {
        $amount = $context->amountBaseUnits;
        $ceiling = $this->effectiveCeiling($context);
        $dailyCap = $this->effectiveDailyCap($context);
        $floor = $this->settings['advisory_confidence_floor'];

        // The reserve floor is clamped at zero so a nonsensical negative limit
        // cannot turn "cannot afford this" into "reserve protected".
        $reserveFloor = max(0, $context->minimumReserveBaseUnits);
        $spentToday = max(0, $context->spentTodayBaseUnits);

        $checks = [
            'amount_positive' => $amount > 0,
            // Advisory facts. Read here and nowhere else in this method.
            'advisory_present' => $context->hasAdvisoryClassification(),
            'advisory_confident' => $context->hasAdvisoryClassification()
                && $context->advisoryConfidence !== null
                && $context->advisoryConfidence >= $floor,
            'advisory_anomaly_free' => $context->advisoryAnomalyFlags === [],
            // Deterministic facts only. The advisory category is not one of them.
            'category_allow_listed' => $context->categoryAllowListed
                && in_array($context->category, $this->allowListFor($context), true),
            'vendor_destination_verified' => $context->hasVerifiedVendorDestination,
            'reserve_protected' => BigInteger::of($context->availableBalanceBaseUnits)
                ->minus($amount)->isGreaterThan($reserveFloor),
            'within_auto_pay_ceiling' => BigInteger::of($amount)->isLessThanOrEqualTo($ceiling),
            'within_daily_cap' => BigInteger::of($spentToday)
                ->plus($amount)->isLessThanOrEqualTo($dailyCap),
        ];

        // 0. A bill of zero or less is not a payment. Nothing to escalate for.
        if (! $checks['amount_positive']) {
            return $this->decide(
                AgentDecisionType::REJECT,
                self::CODE_AMOUNT_NOT_POSITIVE,
                false,
                'Bill amount is not positive, so there is nothing to pay. No human approval is required; the bill needs correcting, not authorising.',
                ['Bill amount is not a positive number of base units.'],
                $checks,
            );
        }

        // 1. Uncertainty in an advisory classification blocks automation. The
        //    most specific reason is reported: flags beat absence beats doubt.
        if (! $checks['advisory_anomaly_free']) {
            $flags = implode(', ', $context->advisoryAnomalyFlags);

            return $this->decide(
                AgentDecisionType::ESCALATE,
                self::CODE_ADVISORY_ANOMALY_FLAGS,
                true,
                "Advisory classification raised anomalies ({$flags}). An automated payment must not proceed on a classification that is itself flagging something.",
                ["Advisory anomaly flags present: {$flags}."],
                $checks,
            );
        }

        if (! $checks['advisory_present']) {
            return $this->decide(
                AgentDecisionType::ESCALATE,
                self::CODE_ADVISORY_MISSING,
                true,
                'No advisory classification was supplied for this bill. Absence is treated as unknown, not as permission, so a person reviews it.',
                ['Advisory classification is absent; an unclassified bill is not auto-payable.'],
                $checks,
            );
        }

        if (! $checks['advisory_confident']) {
            return $this->decide(
                AgentDecisionType::ESCALATE,
                self::CODE_ADVISORY_CONFIDENCE,
                true,
                'Advisory confidence is below the floor required for unattended payment. A classifier that is unsure must never be able to enable automation.',
                ['Advisory confidence is below the floor of '.$this->confidence($floor).'.'],
                $checks,
            );
        }

        // 2. Only fixed-purpose fees the college itself levies may pay alone.
        if (! $checks['category_allow_listed']) {
            return $this->decide(
                AgentDecisionType::ESCALATE,
                self::CODE_CATEGORY_NOT_ALLOWED,
                true,
                "Bill category '{$context->category}' is not on the unattended allow-list. Advisory confidence does not enter this check: only the category the registrar recorded does.",
                ["Category '{$context->category}' is not allow-listed for unattended payment."],
                $checks,
            );
        }

        // 3. A destination that has not been verified is never invented.
        if (! $checks['vendor_destination_verified']) {
            return $this->decide(
                AgentDecisionType::ESCALATE,
                self::CODE_DESTINATION_UNVERIFIED,
                true,
                'No verified payout destination exists for this payee. A missing destination escalates rather than falling back to a generated address.',
                ['No verified destination exists for the payee.'],
                $checks,
            );
        }

        // 4. Treasury safety outranks every other consideration.
        if (! $checks['reserve_protected']) {
            return $this->decide(
                AgentDecisionType::HOLD,
                self::CODE_RESERVE_BREACH,
                true,
                'Payment would drop the available balance below the minimum operating reserve. Held until the reserve is restored or a person authorises it.',
                [
                    'Post-payment balance would fall below the minimum reserve of '.$this->decimal($reserveFloor).'.',
                ],
                $checks,
            );
        }

        // 5. Above the unattended ceiling, the amount deserves a person.
        if (! $checks['within_auto_pay_ceiling']) {
            return $this->decide(
                AgentDecisionType::ESCALATE,
                self::CODE_CEILING_EXCEEDED,
                true,
                'Bill amount exceeds the autonomous payment ceiling. Escalated for human authorisation.',
                ['Amount of '.$this->decimal($amount).' exceeds the unattended ceiling of '.$this->decimal($ceiling).'.'],
                $checks,
            );
        }

        // 6. The day's unattended outflow is bounded too.
        if (! $checks['within_daily_cap']) {
            return $this->decide(
                AgentDecisionType::ESCALATE,
                self::CODE_DAILY_CAP_EXCEEDED,
                true,
                'Payment would push today\'s unattended spending past the daily cap. Escalated for approval.',
                [
                    'Already committed today '.$this->decimal($spentToday).' plus this bill exceeds the daily cap of '.$this->decimal($dailyCap).'.',
                ],
                $checks,
            );
        }

        // 7. Everything passed. The exact amount, never a rounded or
        //    reconstructed one.
        return $this->decide(
            AgentDecisionType::AUTO_APPROVE,
            self::CODE_AUTO_PAYMENT,
            false,
            'Approved for unattended payment: a confident anomaly-free classification of an allow-listed category, a verified destination, the minimum reserve intact, and the amount within both the per-bill ceiling and the daily cap.',
            [],
            $checks,
            $amount,
        );
    }

    /** The per-bill ceiling in force: the tighter of the policy default and the institution's. */
    public function autoPayCeilingBaseUnits(BillPolicyContext $context): int
    {
        return $this->effectiveCeiling($context);
    }

    /** The daily cap in force: the tighter of the policy default and the institution's. */
    public function dailyCapBaseUnits(BillPolicyContext $context): int
    {
        return $this->effectiveDailyCap($context);
    }

    public function advisoryConfidenceFloor(): float
    {
        return $this->settings['advisory_confidence_floor'];
    }

    /**
     * The categories this policy will ever let pay unattended.
     *
     * An institution's list is intersected with it, so a wider institutional
     * list cannot widen the unattended lane. An empty institutional list means
     * "not supplied" and leaves the policy's own list in force.
     *
     * @return list<string>
     */
    public function allowedCategories(): array
    {
        return $this->settings['allowed_categories'];
    }

    /**
     * @return list<string>
     */
    private function allowListFor(BillPolicyContext $context): array
    {
        if ($context->allowedCategories === []) {
            return $this->settings['allowed_categories'];
        }

        return array_values(array_intersect($this->settings['allowed_categories'], $context->allowedCategories));
    }

    private function effectiveCeiling(BillPolicyContext $context): int
    {
        if (! $context->hasInstitutionLimits()) {
            return $this->settings['auto_pay_ceiling_base_units'];
        }

        return min($context->autoPayCeilingBaseUnits, $this->settings['auto_pay_ceiling_base_units']);
    }

    private function effectiveDailyCap(BillPolicyContext $context): int
    {
        if (! $context->hasInstitutionLimits()) {
            return $this->settings['daily_cap_base_units'];
        }

        return min($context->dailyCapBaseUnits, $this->settings['daily_cap_base_units']);
    }

    /**
     * @param  array<string, bool>  $checks
     * @param  list<string>  $violations
     */
    private function decide(
        AgentDecisionType $decision,
        string $policyCode,
        bool $requiresHumanApproval,
        string $reasoning,
        array $violations,
        array $checks,
        int $approvedAmountBaseUnits = 0,
    ): BillPolicyDecision {
        return new BillPolicyDecision(
            decision: $decision,
            approvedAmountBaseUnits: $approvedAmountBaseUnits,
            requiresHumanApproval: $requiresHumanApproval,
            policyCode: $policyCode,
            reasoning: $reasoning,
            violations: $violations,
            checks: $checks,
        );
    }

    /** Exact base units to a decimal string, with no float in the path. */
    private function decimal(int $baseUnits): string
    {
        return (string) BigDecimal::ofUnscaledValue($baseUnits, 6);
    }

    /** Render a confidence floor for a human without pretending to more precision than it has. */
    private function confidence(float $floor): string
    {
        return number_format($floor, 2, '.', '');
    }

    private function label(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('allowed_categories must be a list of category labels.');
        }

        return $value;
    }

    private function positiveUnits(mixed $value, string $setting): int
    {
        if (! is_int($value) || $value <= 0) {
            throw new InvalidArgumentException($setting.' must be a positive whole number of base units.');
        }

        return $value;
    }
}
