<?php

declare(strict_types=1);

use App\Enums\AgentDecisionType;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Invoice;
use App\Models\Organization;
use App\Services\FinancialPolicyEngine;
use Database\Seeders\DatabaseSeeder;

/**
 * The demo has to settle for real on Arc testnet, which caps a funded agent
 * wallet near 120 USDC. These tests pin the seeded figures and the branch each
 * one takes, so scaling the scenarios can never quietly stop exercising the
 * escalate, hold and reject paths again.
 */

/**
 * Seed the demo and return the decision each invoice takes.
 *
 * @return array<string, AgentDecisionType>
 */
function evaluateSeededInvoices(): array
{
    $org = Organization::firstOrFail();
    $engine = app(FinancialPolicyEngine::class);

    return $org->invoices()
        ->orderBy('amount')
        ->get()
        ->mapWithKeys(fn (Invoice $invoice): array => [
            $invoice->reference => $engine->evaluateInvoice($invoice, $org->primaryWallet())->decision,
        ])
        ->all();
}

it('keeps every seeded scenario inside what the testnet faucet can fund', function (): void {
    $this->seed(DatabaseSeeder::class);

    $org = Organization::firstOrFail();
    $wallet = $org->primaryWallet();

    // The Circle faucet mints 20 USDC per drip and rate-limits after about five.
    $fundedCeiling = 120.00;

    expect($wallet->balance)->toBeLessThanOrEqual($fundedCeiling)
        ->and($wallet->balance)->toBe(120.00);

    // Only auto-paid invoices move money, so the cycle has to stay affordable.
    $engine = app(FinancialPolicyEngine::class);
    $autoPaid = $org->invoices->filter(
        fn (Invoice $invoice): bool => $engine->evaluateInvoice($invoice, $wallet)->decision === AgentDecisionType::AUTO_APPROVE
    );

    $disbursed = (float) $autoPaid->sum('amount');

    expect($autoPaid)->not->toBeEmpty()
        ->and($disbursed)->toBeLessThan($wallet->balance)
        // And it must not breach the reserve once spent.
        ->and($wallet->balance - $disbursed)->toBeGreaterThanOrEqual($org->minimum_reserve);
});

it('drives a different policy branch for every seeded invoice', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(evaluateSeededInvoices())->toBe([
        'INV-FIBER-30' => AgentDecisionType::AUTO_APPROVE,
        'INV-CLOUD-45' => AgentDecisionType::AUTO_APPROVE,
        'INV-UNVERIFIED-60' => AgentDecisionType::ESCALATE,
        'INV-LAB-90' => AgentDecisionType::ESCALATE,
        'INV-SUPPLY-200' => AgentDecisionType::HOLD,
        'INV-HAZARD-2000' => AgentDecisionType::REJECT,
    ]);
});

it('covers all four reachable policy decisions across the seeded scenarios', function (): void {
    $this->seed(DatabaseSeeder::class);

    // Pure enums cannot go through array_unique(), so compare on names.
    $branches = collect(evaluateSeededInvoices())
        ->map(fn (AgentDecisionType $decision): string => $decision->name)
        ->unique()
        ->values()
        ->all();

    expect($branches)->toEqualCanonicalizing([
        AgentDecisionType::AUTO_APPROVE->name,
        AgentDecisionType::ESCALATE->name,
        AgentDecisionType::HOLD->name,
        AgentDecisionType::REJECT->name,
    ]);
});

it('seeds a bounded split so the assistance demo has something to narrate', function (): void {
    $this->seed(DatabaseSeeder::class);

    $policy = AssistancePolicyVersion::active();
    $request = AssistanceRequest::firstOrFail();

    // Above the auto limit, so part is approved and the remainder escalated.
    expect((int) $request->requested_amount)->toBeGreaterThan((int) $policy->auto_limit_base_units)
        // And small enough to settle on a testnet-funded wallet.
        ->and((int) $request->requested_amount)->toBeLessThanOrEqual(15_000000);
});

it('re-seeding converges on the same thresholds instead of drifting', function (): void {
    $this->seed(DatabaseSeeder::class);
    $before = Organization::firstOrFail()->only([
        'minimum_reserve', 'max_auto_payment', 'max_daily_disbursement', 'human_approval_threshold',
    ]);

    $this->seed(DatabaseSeeder::class);
    $after = Organization::firstOrFail()->only([
        'minimum_reserve', 'max_auto_payment', 'max_daily_disbursement', 'human_approval_threshold',
    ]);

    expect($after)->toEqual($before);
});
