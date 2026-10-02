<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\Organization;

/**
 * Builds a `SettlementOperator` bound to real records.
 *
 * The agent's constructor takes an `Organization`, an `AssistanceFund` and a
 * `AssistancePolicyVersion`. Laravel's container will happily instantiate all
 * three as *empty, non-existent models* when resolving `SettlementOperator`,
 * because Eloquent models have no required constructor arguments.
 *
 * The result is an agent whose tools hold a blank organization: `primaryWallet()`
 * returns null, so `DisburseAssistance::handle()` refuses with "The organization
 * has no active wallet". The right outcome for the wrong reason, and it fails
 * differently once a wallet row exists — which is exactly the sort of bug that
 * passes a demo and breaks in production.
 *
 * Resolving through here instead means a missing record is reported as a
 * missing record.
 */
final class SettlementOperatorFactory
{
    /**
     * Build an operator for an organization, or null if it is not fully configured.
     */
    public static function make(?Organization $organization = null): ?SettlementOperator
    {
        $organization ??= Organization::first();

        if (! $organization?->exists) {
            return null;
        }

        $fund = AssistanceFund::where('organization_id', $organization->id)->first();
        $policyVersion = AssistancePolicyVersion::active();

        if ($fund === null || $policyVersion === null) {
            return null;
        }

        return SettlementOperator::make($organization, $fund, $policyVersion);
    }

    /**
     * Build an operator or fail loudly.
     *
     * Used where continuing without one would silently skip a payment.
     *
     * @throws \RuntimeException when the organization, fund or policy version is missing
     */
    public static function makeOrFail(?Organization $organization = null): SettlementOperator
    {
        $operator = self::make($organization);

        if ($operator === null) {
            throw new \RuntimeException(
                'SettlementOperator needs a persisted organization, an assistance fund and an active policy version. '
                .'Run `php artisan db:seed` or `eduflow:demo` first.'
            );
        }

        return $operator;
    }
}
