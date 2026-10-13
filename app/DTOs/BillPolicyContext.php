<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Every fact the bill-payment policy needs, as plain values.
 *
 * This type deliberately has **no model dependencies**. The policy that reads
 * it can therefore be exercised as a pure function with no database, no
 * container, and no fixtures -- which is the only honest way to demonstrate
 * that PLAN.md §17.2 holds ("No model runs in the finance decision path").
 * Whoever assembles a context is responsible for having read those facts from
 * the institution's own records; the policy trusts the values and re-derives
 * every conclusion from them.
 *
 * All money is exact integer **base units** (USDC is 6 decimals, so 1.00 USDC
 * is 1_000_000). A float cannot hold a 6-decimal amount without drift, and a
 * policy that decides on drifted money is not deterministic in the sense that
 * matters. See `App\DTOs\Money`.
 *
 * The three fields ending in `BaseUnits` that describe *institution limits*
 * (`autoPayCeilingBaseUnits`, `dailyCapBaseUnits`, `minimumReserveBaseUnits`)
 * may only ever be **tighter** than the policy's own built-in defaults. An
 * institution can be more cautious than the standing policy; it can never be
 * more permissive.
 *
 * The `advisory*` fields carry an optional third-party classification (for
 * example `App\Ai\Advisory\AdvisoryEnvelope`). They are **advisory only** and
 * structurally incapable of widening an outcome:
 *
 *  - They are read only by check 1, and only to *stop* automation.
 *  - `advisoryCategory` is never matched against the allow-list -- the bill's
 *    own recorded `category` is -- so a classifier cannot grant a category.
 *  - They contain no amount, no recipient and no ceiling, so there is nothing
 *    in them a caller could promote into an approved payment.
 *  - An absent advisory is treated as "unknown", which is the conservative
 *    branch: escalate. Fail-closed is the only safe direction for a
 *    classification.
 */
final readonly class BillPolicyContext
{
    /**
     * @param  int  $amountBaseUnits  The exact bill amount owed, in base units. Never a float.
     * @param  int  $availableBalanceBaseUnits  The institution's observed spendable balance, in base units.
     * @param  int  $minimumReserveBaseUnits  The floor the balance may not fall below by paying this bill.
     * @param  int  $autoPayCeilingBaseUnits  The largest single unattended payment the institution tolerates.
     * @param  int  $dailyCapBaseUnits  The most the institution will spend unattended across the whole day.
     * @param  int  $spentTodayBaseUnits  How much of that daily cap is already committed.
     * @param  string  $category  The category the registrar recorded on the bill, e.g. "laboratory".
     * @param  bool  $categoryAllowListed  Whether the caller read `category` as on the institution's allow-list.
     * @param  bool  $hasVerifiedVendorDestination  Whether a verified payout destination exists for the payee.
     * @param  list<string>  $allowedCategories  The allow-list the caller matched against, for independent re-derivation.
     * @param  string|null  $advisoryCategory  ADVISORY ONLY. Model-assigned label. Never grants a permission.
     * @param  float|null  $advisoryConfidence  ADVISORY ONLY. Model self-reported confidence, 0..1.
     * @param  list<string>  $advisoryAnomalyFlags  ADVISORY ONLY. Signals worth a human's attention.
     */
    public function __construct(
        public int $amountBaseUnits,
        public int $availableBalanceBaseUnits,
        public int $minimumReserveBaseUnits,
        public int $autoPayCeilingBaseUnits,
        public int $dailyCapBaseUnits,
        public int $spentTodayBaseUnits,
        public string $category,
        public bool $categoryAllowListed,
        public bool $hasVerifiedVendorDestination,
        public array $allowedCategories = [],
        public ?string $advisoryCategory = null,
        public ?float $advisoryConfidence = null,
        public array $advisoryAnomalyFlags = [],
    ) {}

    /**
     * Whether an advisory classification was supplied at all.
     *
     * `false` here is not "no anomalies, full confidence" -- it is "nobody
     * classified this", which the policy reads as unknown rather than clean.
     */
    public function hasAdvisoryClassification(): bool
    {
        return $this->advisoryCategory !== null && $this->advisoryConfidence !== null;
    }

    /**
     * Whether the institution's own recorded limits are the ones in force, or
     * whether nothing was supplied and the policy defaults govern.
     */
    public function hasInstitutionLimits(): bool
    {
        return $this->autoPayCeilingBaseUnits > 0 && $this->dailyCapBaseUnits > 0;
    }
}
