<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FinancePolicyVersion;
use App\Models\MandateOccurrence;
use App\Models\PaymentIntent;
use App\Models\RecurringMandate;
use App\Models\RecurringMandateReview;
use App\Models\VendorDestinationVersion;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The deterministic half of the autonomous lane.
 *
 * §18.1 is the whole reason this class exists in the shape it does:
 *
 *   A standing human mandate decides the class of payment. Deterministic PHP
 *   decides each individual occurrence. The model is not in the release path
 *   at all.
 *
 * So this service accepts no model, no provider handle, no session and no clock
 * it does not have to have handed to it. It is a pure function of evidence: give
 * it a mandate, an occurrence and a snapshot of live facts, and it returns one
 * of exactly three dispositions.
 *
 * - `release` — every check passed, so this occurrence may proceed without a
 *   further human approval. It is *not* a payment: it still needs its own
 *   funding window, reservation and outbox entry like anything else.
 * - `escalate` — looks valid but sits outside what the mandate authorized (price
 *   change, changed destination, over ceiling, over cap). Goes to a human.
 * - `blocked` — invalid, restricted, insufficient, integrity or network failure.
 *   **Never an approvable override.** There is no string, flag or argument that
 *   turns a `blocked` into a `release`; the only route out is new evidence.
 *
 * Every amount is handled as integer base units or exact `BigInteger`. Nothing in
 * this class produces a float figure for a money decision.
 */
final readonly class MandateReleaseEvaluator
{
    /**
     * @param  array<string, mixed>  $facts  Live facts read by the caller:
     *                                       `at`, `available_cash_base_units`,
     *                                       `consuming_holds_base_units`,
     *                                       `daily_released_base_units`,
     *                                       `period_released_base_units`,
     *                                       `bill_amount_base_units`,
     *                                       `max_fee_base_units`,
     *                                       `destination_digest`,
     *                                       `policy_digest`, `is_fake`,
     *                                       `stop_switch`, `funding_window_valid`,
     *                                       `obligation_key`
     */
    public function evaluate(RecurringMandate $mandate, array $facts): array
    {
        $checks = [];
        $at = $facts['at'] instanceof CarbonImmutable ? $facts['at'] : CarbonImmutable::now();
        $checks['evaluated_at'] = $at->toIso8601String();

        $this->checkMandateAuthorized($mandate, $checks);
        $this->checkMandateLive($mandate, $at, $checks);
        $this->checkChainAndCurrency($mandate, $checks);
        $this->checkObligationIdentity($mandate, $facts, $checks);
        $this->checkNotPreviouslyFulfilled($mandate, $facts, $checks);
        $this->checkAmountWithinCeiling($mandate, $facts, $checks);
        $this->checkFeeWithinCeiling($mandate, $facts, $checks);
        $this->checkDestinationUnchanged($mandate, $facts, $checks);
        $this->checkPolicyUnchanged($mandate, $facts, $checks);
        $this->checkCapacity($facts, $checks);
        $this->checkCumulativeCaps($mandate, $facts, $checks);
        $this->checkRailReadiness($facts, $checks);

        // `evaluated_at` is metadata rather than a check, so the filters guard
        // on shape rather than assuming every entry is a gate.
        $blocked = array_values(array_filter($checks, fn (mixed $check): bool => is_array($check)
            && $check['severity'] === 'blocked' && $check['passed'] === false));
        $escalated = array_values(array_filter($checks, fn (mixed $check): bool => is_array($check)
            && $check['severity'] === 'escalate' && $check['passed'] === false));

        $checks['passed'] = $blocked === [] && $escalated === [];
        $checks['disposition'] = match (true) {
            $blocked !== [] => 'blocked',
            $escalated !== [] => 'escalate',
            default => 'release',
        };

        return $checks;
    }

    /**
     * A one-line, human-readable reason for the disposition.
     *
     * The named gate that held is named in full; when everything passed this
     * says what was verified rather than "ok", because a supervisor reading a
     * released occurrence should be able to see what it passed.
     *
     * @param  array<string, mixed>  $checks
     */
    public static function summary(array $checks): string
    {
        $failed = [];
        foreach ($checks as $name => $check) {
            if (! is_array($check)) {
                continue;
            }
            if (($check['passed'] ?? true) === true) {
                continue;
            }
            $failed[] = ($check['reason'] ?? $name);
        }

        return match ($checks['disposition'] ?? 'blocked') {
            'release' => count($failed) === 0
                ? 'Every release check passed: authorized, live, exact testnet identity, unique occurrence, within ceilings and caps, current destination and policy, and funded.'
                : 'Released with collapsing checks: '.implode(' ', $failed),
            'escalate' => 'Sent for human review: '.implode(' ', $failed),
            default => 'Blocked: '.implode(' ', $failed),
        };
    }

    /**
     * Record one check's outcome and why, or nothing at all when it passes.
     *
     * A check that fails says which gate held and what would change it, so an
     * operator sees "destination changed" rather than "cannot pay".
     *
     * @param  array<string, mixed>  $checks
     */
    private function record(array &$checks, string $name, bool $passed, string $severity, ?string $reason = null): void
    {
        $checks[$name] = ['passed' => $passed, 'severity' => $severity];

        if (! $passed && $reason !== null) {
            $checks[$name]['reason'] = $reason;
        }
    }

    /** @param array<string, mixed> $checks */
    private function checkMandateAuthorized(RecurringMandate $mandate, array &$checks): void
    {
        $review = $mandate->review;

        $this->record($checks, 'mandate_authorized', $review instanceof RecurringMandateReview
            && $review->approves() && $review->hasValidEvidence($mandate), 'blocked',
            'A mandate that no independent reviewer approved authorizes nothing, however plausible it looks.');
    }

    /** @param array<string, mixed> $checks */
    private function checkMandateLive(RecurringMandate $mandate, CarbonImmutable $at, array &$checks): void
    {
        $this->record($checks, 'mandate_live', $mandate->isLive($at), 'blocked',
            'The mandate is not currently live: revoked, not yet started, or past its end date.');

        $this->record($checks, 'allowance_nonzero', ! $mandate->releasesNothing(), 'blocked',
            'The approved per-occurrence ceiling is zero. The initial allowance is zero by design, so nothing is released until a reviewer raises it.');
    }

    /** @param array<string, mixed> $checks */
    private function checkChainAndCurrency(RecurringMandate $mandate, array &$checks): void
    {
        // Testnet only, and the identity is exact. A mainnet chain id must never
        // be admitted by a lane built for testnet, even accidentally.
        $this->record($checks, 'chain_is_configured_testnet', $mandate->chain === 'ARC-TESTNET'
            && $mandate->chain_id === 5042002
            && $mandate->chain === config('lepton.arc.chain')
            && $mandate->chain_id === config('lepton.arc.chain_id'), 'blocked',
            'The mandate must name the configured Arc testnet identity exactly; no fallback to another rail exists.');
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $checks
     */
    private function checkObligationIdentity(RecurringMandate $mandate, array &$facts, array &$checks): void
    {
        $key = $facts['occurrence_key'] ?? null;
        $claimed = $facts['occurrence_digest'] ?? null;

        // The digest that will be recorded must be the one *derived from* this
        // mandate and this occurrence key. Accepting a caller's digest without
        // recomputing it would let a caller mint a fresh identity for an
        // obligation it has already paid, which is the whole failure mode. The
        // digest already covers the obligation, so recomputing it is enough.
        $matches = is_string($key) && $key !== '' && is_string($claimed)
            && hash_equals($claimed, $this->occurrenceDigest($mandate, $key));

        $this->record($checks, 'obligation_identity', $matches, 'blocked',
            'The occurrence identity does not belong to the mandate\'s own obligation, or does not match its key. Releasing it would pay something nobody authorized.');
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $checks
     */
    private function checkNotPreviouslyFulfilled(RecurringMandate $mandate, array $facts, array &$checks): void
    {
        $key = (string) ($facts['occurrence_digest'] ?? '');
        $digest = MandateOccurrence::query()
            ->where('recurring_mandate_id', $mandate->id)
            ->where('occurrence_digest', $key)
            ->exists();

        $this->record($checks, 'no_prior_fulfilment', ! $digest, 'blocked',
            'This obligation occurrence has already been recorded. A repeat is the same payment, not a second one.');
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $checks
     */
    private function checkAmountWithinCeiling(RecurringMandate $mandate, array $facts, array &$checks): void
    {
        $bill = $this->units($facts['bill_amount_base_units'] ?? 0);
        $ceiling = BigInteger::of($mandate->per_occurrence_ceiling_base_units);

        $this->record($checks, 'amount_within_ceiling', $bill->isLessThanOrEqualTo($ceiling), 'escalate',
            sprintf('Exact bill amount %s exceeds the approved per-occurrence ceiling of %s.',
                $bill, $mandate->per_occurrence_ceiling_base_units));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $checks
     */
    private function checkFeeWithinCeiling(RecurringMandate $mandate, array $facts, array &$checks): void
    {
        $fee = $this->units($facts['max_fee_base_units'] ?? 0);
        $ceiling = BigInteger::of($mandate->fee_ceiling_base_units);

        $this->record($checks, 'fee_within_ceiling', $fee->isLessThanOrEqualTo($ceiling), 'escalate',
            sprintf('Exact fee %s exceeds the approved fee ceiling of %s.',
                $fee, $mandate->fee_ceiling_base_units));
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $checks
     */
    private function checkDestinationUnchanged(RecurringMandate $mandate, array $facts, array &$checks): void
    {
        $version = VendorDestinationVersion::query()
            ->where('vendor_id', $mandate->vendor_id)
            ->whereKey($mandate->vendor_destination_version_id)
            ->first();

        $matches = $version instanceof VendorDestinationVersion
            && $version->hasValidContent()
            && $version->chain === $mandate->chain
            && $version->chain_id === $mandate->chain_id
            && hash_equals((string) $facts['destination_digest'], $version->content_digest);

        $this->record($checks, 'destination_unchanged', $matches, 'escalate',
            'The vendor destination differs from the mandate\'s currently reviewed version. A changed recipient is held for renewed review, never guessed from the last payment.');
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $checks
     */
    private function checkPolicyUnchanged(RecurringMandate $mandate, array $facts, array &$checks): void
    {
        $current = FinancePolicyVersion::query()
            ->where('organization_id', $mandate->organization_id)
            ->whereKey($mandate->finance_policy_version_id)
            ->first();

        $this->record($checks, 'policy_unchanged', $current instanceof FinancePolicyVersion
            && $current->hasValidContent()
            && hash_equals((string) $facts['policy_digest'], $current->content_digest), 'escalate',
            'The active finance policy is no longer the version this mandate was approved against.');
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $checks
     */
    private function checkCapacity(array $facts, array &$checks): void
    {
        $bill = $this->units($facts['bill_amount_base_units'] ?? 0);
        $fee = $this->units($facts['max_fee_base_units'] ?? 0);
        $holds = $this->units($facts['consuming_holds_base_units'] ?? 0);
        $cash = $this->units($facts['available_cash_base_units'] ?? 0);
        $after = $holds->plus($fee);

        $fits = $bill->plus($fee)->plus($holds)->isLessThanOrEqualTo($cash) && ! $after->isNegative();

        $this->record($checks, 'capacity_for_bill_and_fee', $fits, 'escalate',
            'The bill plus fee plus all active holds no longer fits the available protected cash.');
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $checks
     */
    private function checkCumulativeCaps(RecurringMandate $mandate, array $facts, array &$checks): void
    {
        $bill = $this->units($facts['bill_amount_base_units'] ?? 0);
        $daily = $this->units($facts['daily_released_base_units'] ?? 0);
        $period = $this->units($facts['period_released_base_units'] ?? 0);

        $dailyOk = $daily->plus($bill)->isLessThanOrEqualTo(BigInteger::of($mandate->daily_limit_base_units));
        $periodOk = $period->plus($bill)->isLessThanOrEqualTo(BigInteger::of($mandate->period_limit_base_units));

        $this->record($checks, 'daily_cap', $dailyOk, 'escalate',
            'Releasing this bill would breach the mandate\'s daily cumulative cap. Caps count submitted and settled work without double-counting; they never reset themselves.');

        $this->record($checks, 'period_cap', $periodOk, 'escalate',
            'Releasing this bill would breach the mandate\'s period cumulative cap.');
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $checks
     */
    private function checkRailReadiness(array $facts, array &$checks): void
    {
        // Simulated evidence must never be released, and the stop switch holds
        // work without argument. A failed funding window blocks rather than
        // escalates: nothing about it is a policy question.
        $this->record($checks, 'evidence_is_real', ($facts['is_fake'] ?? true) === false, 'blocked',
            'The bound evidence came from the fake driver. Simulation is never released on the autonomous lane.');

        $this->record($checks, 'stop_switch_clear', ($facts['stop_switch'] ?? true) === false, 'blocked',
            'The submission stop switch is engaged. Queued work is held; in-flight evidence is preserved.');

        $this->record($checks, 'funding_window_valid', ($facts['funding_window_valid'] ?? false) === true, 'blocked',
            'No reviewed funding window currently holds capacity for this obligation.');
    }

    /**
     * Deterministic occurrence identity, for the caller to persist.
     *
     * The identity deliberately excludes the amount. Two months of the same
     * obligation are two occurrences because their keys differ; the amount must
     * never be allowed to mint a new identity by drifting.
     */
    public function occurrenceDigest(RecurringMandate $mandate, string $occurrenceKey): string
    {
        return PaymentIntent::digest([
            'institution_id' => $mandate->organization_id,
            'recurring_mandate_id' => $mandate->id,
            'obligation_digest' => $mandate->obligation_digest,
            'occurrence_key' => $occurrenceKey,
        ]);
    }

    /**
     * Read a fact as exact base units.
     *
     * Money decisions in this class are integer-only. A float is rejected
     * rather than accepted-and-rounded, because a ceiling compared against an
     * approximate amount is not a ceiling; a numeric string is parsed exactly.
     */
    private function units(mixed $value): BigInteger
    {
        if ($value instanceof BigInteger) {
            return $value;
        }

        if (is_int($value)) {
            return BigInteger::of($value);
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return BigInteger::of($value);
        }

        throw ValidationException::withMessages([
            'facts' => 'Exact integer base units are required for a money decision; floats and imprecise strings are rejected here.',
        ]);
    }
}
