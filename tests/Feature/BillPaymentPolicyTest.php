<?php

declare(strict_types=1);

use App\DTOs\BillPolicyContext;
use App\DTOs\BillPolicyDecision;
use App\Enums\AgentDecisionType;
use App\Services\BillPaymentPolicy;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * A clean, allow-listed, small bill with a verified destination.
 *
 * Every deviation in this file is a named override on top of this one
 * baseline, so a test states only the fact under examination while the rest of
 * the context stays visibly constant.
 *
 * @param  array<string, mixed>  $overrides
 */
function billContext(array $overrides = []): BillPolicyContext
{
    return new BillPolicyContext(...[
        'amountBaseUnits' => 1_234_567,              // 1.234567 USDC -- awkward on purpose
        'availableBalanceBaseUnits' => 40_000_000,   // 40.00 USDC
        'minimumReserveBaseUnits' => 5_000_000,      // 5.00 USDC
        'autoPayCeilingBaseUnits' => 10_000_000,     // 10.00 USDC, tighter than the policy default
        'dailyCapBaseUnits' => 50_000_000,           // 50.00 USDC, tighter than the policy default
        'spentTodayBaseUnits' => 0,
        'category' => 'laboratory',
        'categoryAllowListed' => true,
        'hasVerifiedVendorDestination' => true,
        'allowedCategories' => ['registration', 'examination', 'laboratory'],
        'advisoryCategory' => 'laboratory',
        'advisoryConfidence' => 0.97,
        'advisoryAnomalyFlags' => [],
        ...$overrides,
    ]);
}

/** Every value a decision exposes, so two decisions can be compared exactly. */
function decisionFingerprint(BillPolicyDecision $decision): array
{
    return [
        'decision' => $decision->decision,
        'approved_amount_base_units' => $decision->approvedAmountBaseUnits,
        'requires_human_approval' => $decision->requiresHumanApproval,
        'policy_code' => $decision->policyCode,
        'reasoning' => $decision->reasoning,
        'violations' => $decision->violations,
        'checks' => $decision->checks,
    ];
}

beforeEach(function (): void {
    $this->policy = new BillPaymentPolicy;
});

test('auto approves a clean allow-listed bill for the exact base-unit amount', function (): void {
    $decision = $this->policy->evaluate(billContext());

    expect($decision->decision)->toBe(AgentDecisionType::AUTO_APPROVE)
        ->and($decision->approvedAmountBaseUnits)->toBe(1_234_567)
        ->and($decision->requiresHumanApproval)->toBeFalse()
        ->and($decision->policyCode)->toBe(BillPaymentPolicy::CODE_AUTO_PAYMENT)
        ->and($decision->violations)->toBe([])
        ->and($decision->isAutoApproved())->toBeTrue()
        ->and($decision->blocksUnattendedPayment())->toBeFalse();

    // 1_234_567 base units renders as exactly 1.234567 USDC. No float is on
    // the path that produced or rendered it, so nothing has been rounded.
    expect((string) BigDecimal::ofUnscaledValue($decision->approvedAmountBaseUnits, 6))->toBe('1.234567');
});

test('exact arithmetic decides the reserve boundary where a float pipeline does not', function (): void {
    $reserve = 5_000_000;
    $floatWouldAllow = 0;

    for ($amount = 1; $amount <= 500; $amount++) {
        // A balance that lands exactly on the reserve floor once this bill pays.
        $balance = $reserve + $amount;
        $exactSaysSafe = ($balance - $amount) > $reserve;
        $floatSaysSafe = ($balance / 1_000_000 - $amount / 1_000_000) >= $reserve / 1_000_000;

        if ($exactSaysSafe !== $floatSaysSafe) {
            $floatWouldAllow++;
        }
    }

    // 500 balances that reach the floor exactly. A float subtraction lands a
    // hair under it and waves every one of them through; exact base units do not.
    expect($floatWouldAllow)->toBe(500);

    $decision = $this->policy->evaluate(billContext([
        'amountBaseUnits' => 1,
        'availableBalanceBaseUnits' => 5_000_001,
        'minimumReserveBaseUnits' => 5_000_000,
        'autoPayCeilingBaseUnits' => 10_000_000,
        'dailyCapBaseUnits' => 50_000_000,
    ]));

    expect($decision->decision)->toBe(AgentDecisionType::HOLD)
        ->and($decision->approvedAmountBaseUnits)->toBe(0)
        ->and($decision->policyCode)->toBe(BillPaymentPolicy::CODE_RESERVE_BREACH)
        ->and($decision->checks['reserve_protected'])->toBeFalse();
});

test('reports every check it evaluated, passing ones included', function (): void {
    $decision = $this->policy->evaluate(billContext());

    expect($decision->checks)->toBe([
        'amount_positive' => true,
        'advisory_present' => true,
        'advisory_confident' => true,
        'advisory_anomaly_free' => true,
        'category_allow_listed' => true,
        'vendor_destination_verified' => true,
        'reserve_protected' => true,
        'within_auto_pay_ceiling' => true,
        'within_daily_cap' => true,
    ]);
});

test('escalates and approves zero above the auto-pay ceiling', function (): void {
    $decision = $this->policy->evaluate(billContext([
        'amountBaseUnits' => 10_000_001,          // one base unit over the ceiling
        'minimumReserveBaseUnits' => 0,
    ]));

    expect($decision->decision)->toBe(AgentDecisionType::ESCALATE)
        ->and($decision->approvedAmountBaseUnits)->toBe(0)
        ->and($decision->policyCode)->toBe(BillPaymentPolicy::CODE_CEILING_EXCEEDED)
        ->and($decision->requiresHumanApproval)->toBeTrue()
        ->and($decision->checks['within_auto_pay_ceiling'])->toBeFalse()
        ->and($decision->checks['reserve_protected'])->toBeTrue();
});

test('holds and approves zero when the payment would breach the minimum reserve', function (): void {
    $decision = $this->policy->evaluate(billContext([
        'amountBaseUnits' => 10_000_000,          // 20.00 - 10.00 < 15.00 reserve
        'availableBalanceBaseUnits' => 20_000_000,
        'minimumReserveBaseUnits' => 15_000_000,
        'autoPayCeilingBaseUnits' => 10_000_000, // the ceiling is satisfied: this is not that stop
    ]));

    expect($decision->decision)->toBe(AgentDecisionType::HOLD)
        ->and($decision->approvedAmountBaseUnits)->toBe(0)
        ->and($decision->policyCode)->toBe(BillPaymentPolicy::CODE_RESERVE_BREACH)
        ->and($decision->isHeld())->toBeTrue()
        ->and($decision->requiresHumanApproval)->toBeTrue()
        ->and($decision->checks['reserve_protected'])->toBeFalse()
        ->and($decision->checks['within_auto_pay_ceiling'])->toBeTrue()
        ->and($decision->checks['within_daily_cap'])->toBeTrue();
});

test('escalates and approves zero when the payment would exceed the daily cap', function (): void {
    $decision = $this->policy->evaluate(billContext([
        'spentTodayBaseUnits' => 49_500_000,      // + 1.234567 > 50.00 cap
        'dailyCapBaseUnits' => 50_000_000,
    ]));

    expect($decision->decision)->toBe(AgentDecisionType::ESCALATE)
        ->and($decision->approvedAmountBaseUnits)->toBe(0)
        ->and($decision->policyCode)->toBe(BillPaymentPolicy::CODE_DAILY_CAP_EXCEEDED)
        ->and($decision->checks['within_daily_cap'])->toBeFalse()
        ->and($decision->checks['within_auto_pay_ceiling'])->toBeTrue();
});

test('holds a payment the treasury cannot afford even with a zero reserve floor', function (): void {
    $decision = $this->policy->evaluate(billContext([
        'amountBaseUnits' => 60_000_000,          // more than the whole balance
        'availableBalanceBaseUnits' => 40_000_000,
        'minimumReserveBaseUnits' => 0,            // a negative floor must not read as "safe"
    ]));

    expect($decision->decision)->toBe(AgentDecisionType::HOLD)
        ->and($decision->approvedAmountBaseUnits)->toBe(0)
        ->and($decision->policyCode)->toBe(BillPaymentPolicy::CODE_RESERVE_BREACH);
});

test('escalates a bill whose advisory confidence is below the floor', function (): void {
    $decision = $this->policy->evaluate(billContext(['advisoryConfidence' => 0.8999]));

    expect($decision->decision)->toBe(AgentDecisionType::ESCALATE)
        ->and($decision->approvedAmountBaseUnits)->toBe(0)
        ->and($decision->policyCode)->toBe(BillPaymentPolicy::CODE_ADVISORY_CONFIDENCE)
        ->and($decision->requiresHumanApproval)->toBeTrue();
});

test('the confidence floor is inclusive, configurable, and refuses nonsense', function (): void {
    $atFloor = $this->policy->evaluate(billContext(['advisoryConfidence' => $this->policy->advisoryConfidenceFloor()]));
    expect($atFloor->decision)->toBe(AgentDecisionType::AUTO_APPROVE);

    $lenient = new BillPaymentPolicy(['advisory_confidence_floor' => 0.5]);
    expect($lenient->evaluate(billContext(['advisoryConfidence' => 0.5]))->decision)->toBe(AgentDecisionType::AUTO_APPROVE)
        ->and($lenient->evaluate(billContext(['advisoryConfidence' => 0.49]))->decision)->toBe(AgentDecisionType::ESCALATE);

    // An operator may retune a floor; they may not make it nonsensical.
    expect(fn (): BillPaymentPolicy => new BillPaymentPolicy(['advisory_confidence_floor' => 1.5]))->toThrow(InvalidArgumentException::class)
        ->and(fn (): BillPaymentPolicy => new BillPaymentPolicy(['auto_pay_ceiling_base_units' => 0]))->toThrow(InvalidArgumentException::class)
        ->and(fn (): BillPaymentPolicy => new BillPaymentPolicy(['daily_cap_base_units' => -1]))->toThrow(InvalidArgumentException::class)
        ->and(fn (): BillPaymentPolicy => new BillPaymentPolicy(['allowed_categories' => 'laboratory']))->toThrow(InvalidArgumentException::class);
});

test('escalates a bill carrying advisory anomaly flags even at perfect confidence', function (): void {
    $decision = $this->policy->evaluate(billContext([
        'advisoryConfidence' => 1.0,
        'advisoryAnomalyFlags' => ['amount_differs_from_history', 'payee_name_changed'],
    ]));

    expect($decision->decision)->toBe(AgentDecisionType::ESCALATE)
        ->and($decision->approvedAmountBaseUnits)->toBe(0)
        ->and($decision->policyCode)->toBe(BillPaymentPolicy::CODE_ADVISORY_ANOMALY_FLAGS)
        ->and($decision->violations[0])->toContain('amount_differs_from_history');
});

test('escalates a non allow-listed category even with a maximally confident clean advisory', function (): void {
    $decision = $this->policy->evaluate(billContext([
        'category' => 'miscellaneous',
        'categoryAllowListed' => false,
        'allowedCategories' => ['registration', 'examination', 'laboratory'],
        'advisoryCategory' => 'laboratory',       // the classifier helpfully says "fine"
        'advisoryConfidence' => 1.0,
        'advisoryAnomalyFlags' => [],
    ]));

    expect($decision->decision)->toBe(AgentDecisionType::ESCALATE)
        ->and($decision->approvedAmountBaseUnits)->toBe(0)
        ->and($decision->policyCode)->toBe(BillPaymentPolicy::CODE_CATEGORY_NOT_ALLOWED);
});

test('the advisory category label cannot grant a category to the allow-list', function (): void {
    // The same bill. The only difference is what the advisory calls it.
    $labelled = $this->policy->evaluate(billContext([
        'category' => 'miscellaneous',
        'categoryAllowListed' => false,
        'advisoryCategory' => 'laboratory',
        'advisoryConfidence' => 1.0,
    ]));
    $silent = $this->policy->evaluate(billContext([
        'category' => 'miscellaneous',
        'categoryAllowListed' => false,
        'advisoryCategory' => 'capex',
        'advisoryConfidence' => 1.0,
    ]));

    expect(decisionFingerprint($labelled))->toBe(decisionFingerprint($silent));
});

test('an institution cannot widen the unattended lane beyond the policy allow-list', function (): void {
    $decision = (new BillPaymentPolicy)->evaluate(billContext([
        'category' => 'capex',
        'categoryAllowListed' => true,                      // the institution says yes
        'allowedCategories' => ['capex', 'laboratory'],    // and offers a list including it
        'advisoryCategory' => 'capex',
        'advisoryConfidence' => 1.0,
    ]));

    expect($decision->decision)->toBe(AgentDecisionType::ESCALATE)
        ->and($decision->policyCode)->toBe(BillPaymentPolicy::CODE_CATEGORY_NOT_ALLOWED)
        ->and((new BillPaymentPolicy)->allowedCategories())->toBe(['registration', 'examination', 'laboratory']);
});

test('escalates when the advisory classification is absent rather than assuming it is clean', function (): void {
    $decision = $this->policy->evaluate(billContext([
        'advisoryCategory' => null,
        'advisoryConfidence' => null,
    ]));

    expect($decision->decision)->toBe(AgentDecisionType::ESCALATE)
        ->and($decision->approvedAmountBaseUnits)->toBe(0)
        ->and($decision->policyCode)->toBe(BillPaymentPolicy::CODE_ADVISORY_MISSING)
        ->and($decision->requiresHumanApproval)->toBeTrue()
        ->and($decision->reasoning)->toContain('Absence is treated as unknown');
});

test('escalates a half-supplied advisory classification', function (): void {
    // A category with no confidence, or confidence with no category, is an
    // incomplete classification -- not a complete one with a low score.
    $categoryOnly = $this->policy->evaluate(billContext(['advisoryConfidence' => null]));
    $confidenceOnly = $this->policy->evaluate(billContext(['advisoryCategory' => null]));

    expect($categoryOnly->policyCode)->toBe(BillPaymentPolicy::CODE_ADVISORY_MISSING)
        ->and($confidenceOnly->policyCode)->toBe(BillPaymentPolicy::CODE_ADVISORY_MISSING);
});

test('escalates when no verified vendor destination exists', function (): void {
    $decision = $this->policy->evaluate(billContext(['hasVerifiedVendorDestination' => false]));

    expect($decision->decision)->toBe(AgentDecisionType::ESCALATE)
        ->and($decision->approvedAmountBaseUnits)->toBe(0)
        ->and($decision->policyCode)->toBe(BillPaymentPolicy::CODE_DESTINATION_UNVERIFIED)
        ->and($decision->reasoning)->toContain('rather than falling back to a generated address');
});

test('the check order surfaces the most specific stop first', function (): void {
    $permissive = new BillPaymentPolicy([
        'auto_pay_ceiling_base_units' => 50_000_000,
        'daily_cap_base_units' => 50_000_000,
    ]);

    // Everything is wrong at once. Check 1 wins.
    expect($permissive->evaluate(billContext([
        'amountBaseUnits' => 40_000_000,
        'availableBalanceBaseUnits' => 1_000_000,
        'minimumReserveBaseUnits' => 50_000_000,
        'category' => 'miscellaneous',
        'categoryAllowListed' => false,
        'hasVerifiedVendorDestination' => false,
        'spentTodayBaseUnits' => 49_000_000,
        'advisoryConfidence' => 0.1,
        'advisoryAnomalyFlags' => ['impossible'],
    ]))->policyCode)->toBe(BillPaymentPolicy::CODE_ADVISORY_ANOMALY_FLAGS);

    // Anomaly flags are the more specific reason, so they are reported first;
    // strip them and the doubt below the floor surfaces.
    $everythingElseWrong = [
        'amountBaseUnits' => 40_000_000,
        'availableBalanceBaseUnits' => 1_000_000,
        'minimumReserveBaseUnits' => 50_000_000,
        'category' => 'miscellaneous',
        'categoryAllowListed' => false,
        'hasVerifiedVendorDestination' => false,
        'spentTodayBaseUnits' => 49_000_000,
    ];
    expect($permissive->evaluate(billContext([...$everythingElseWrong, 'advisoryConfidence' => 0.1]))
        ->policyCode)->toBe(BillPaymentPolicy::CODE_ADVISORY_CONFIDENCE);

    $clean = [...$everythingElseWrong, 'advisoryConfidence' => 1.0];
    expect($permissive->evaluate(billContext($clean))->policyCode)->toBe(BillPaymentPolicy::CODE_CATEGORY_NOT_ALLOWED);

    $cleanAdvisory = $clean;
    unset($cleanAdvisory['category'], $cleanAdvisory['categoryAllowListed']);
    expect($permissive->evaluate(billContext($cleanAdvisory))->policyCode)->toBe(BillPaymentPolicy::CODE_DESTINATION_UNVERIFIED);

    $verified = [...$cleanAdvisory, 'hasVerifiedVendorDestination' => true];
    expect($permissive->evaluate(billContext($verified))->policyCode)->toBe(BillPaymentPolicy::CODE_RESERVE_BREACH);

    $funded = [...$verified, 'minimumReserveBaseUnits' => 0, 'availableBalanceBaseUnits' => 200_000_000];
    expect($permissive->evaluate(billContext($funded))->policyCode)->toBe(BillPaymentPolicy::CODE_CEILING_EXCEEDED);

    // 40.00 USDC is under the 50.00 ceiling but not under 49.00 + 40.00 of cap.
    $withinCeiling = [...$funded, 'autoPayCeilingBaseUnits' => 45_000_000];
    expect($permissive->evaluate(billContext($withinCeiling))->policyCode)->toBe(BillPaymentPolicy::CODE_DAILY_CAP_EXCEEDED);

    // One more fact satisfied and it pays -- exactly, with no rounding.
    $approved = $permissive->evaluate(billContext([...$withinCeiling, 'spentTodayBaseUnits' => 0]));
    expect($approved->policyCode)->toBe(BillPaymentPolicy::CODE_AUTO_PAYMENT)
        ->and($approved->approvedAmountBaseUnits)->toBe(40_000_000)
        ->and($approved->decision)->toBe(AgentDecisionType::AUTO_APPROVE);
});

test('rejects a bill that is not a positive amount', function (): void {
    $zero = $this->policy->evaluate(billContext(['amountBaseUnits' => 0]));
    $negative = $this->policy->evaluate(billContext(['amountBaseUnits' => -1_000]));

    expect($zero->decision)->toBe(AgentDecisionType::REJECT)
        ->and($zero->policyCode)->toBe(BillPaymentPolicy::CODE_AMOUNT_NOT_POSITIVE)
        ->and($zero->approvedAmountBaseUnits)->toBe(0)
        ->and($negative->decision)->toBe(AgentDecisionType::REJECT)
        ->and($negative->approvedAmountBaseUnits)->toBe(0);
});

test('an institution may tighten a ceiling but never loosen it past the policy default', function (): void {
    $claimsMore = billContext([
        'amountBaseUnits' => 30_000_000,          // 30.00 USDC
        'availableBalanceBaseUnits' => 400_000_000,
        'minimumReserveBaseUnits' => 0,
        'autoPayCeilingBaseUnits' => 100_000_000, // institution claims 100.00
        'dailyCapBaseUnits' => 100_000_000,
    ]);

    // The built-in 25.00 USDC default still governs.
    expect($this->policy->autoPayCeilingBaseUnits($claimsMore))->toBe(BillPaymentPolicy::DEFAULT_AUTO_PAY_CEILING_BASE_UNITS)
        ->and($this->policy->evaluate($claimsMore)->policyCode)->toBe(BillPaymentPolicy::CODE_CEILING_EXCEEDED);

    // An institution that wants less gets less, and the tighter limit decides.
    $claimsLess = billContext([
        'amountBaseUnits' => 5_000_000,
        'availableBalanceBaseUnits' => 400_000_000,
        'minimumReserveBaseUnits' => 0,
        'autoPayCeilingBaseUnits' => 1_000_000,   // institution says 1.00
    ]);
    expect($this->policy->autoPayCeilingBaseUnits($claimsLess))->toBe(1_000_000)
        ->and($this->policy->evaluate($claimsLess)->policyCode)->toBe(BillPaymentPolicy::CODE_CEILING_EXCEEDED);

    // No institution limits supplied at all: the policy defaults govern.
    $noLimits = billContext(['autoPayCeilingBaseUnits' => 0, 'dailyCapBaseUnits' => 0]);
    expect($this->policy->autoPayCeilingBaseUnits($noLimits))->toBe(BillPaymentPolicy::DEFAULT_AUTO_PAY_CEILING_BASE_UNITS)
        ->and($this->policy->dailyCapBaseUnits($noLimits))->toBe(BillPaymentPolicy::DEFAULT_DAILY_CAP_BASE_UNITS);
});

test('ceilings are operator-configurable and take effect without editing the policy', function (): void {
    $raised = new BillPaymentPolicy([
        'auto_pay_ceiling_base_units' => 50_000_000,
        'daily_cap_base_units' => 200_000_000,
    ]);
    $context = billContext([
        'amountBaseUnits' => 30_000_000,
        'availableBalanceBaseUnits' => 400_000_000,
        'minimumReserveBaseUnits' => 0,
        'autoPayCeilingBaseUnits' => 40_000_000,
        'dailyCapBaseUnits' => 40_000_000,
    ]);

    expect($this->policy->evaluate($context)->decision)->toBe(AgentDecisionType::ESCALATE)
        ->and($raised->evaluate($context)->decision)->toBe(AgentDecisionType::AUTO_APPROVE)
        ->and($raised->evaluate($context)->approvedAmountBaseUnits)->toBe(30_000_000);
});

test('a maximally confident advisory cannot raise a ceiling, only change which stop is named', function (): void {
    $context = billContext([
        'amountBaseUnits' => 30_000_000,
        'availableBalanceBaseUnits' => 400_000_000,
        'minimumReserveBaseUnits' => 0,
        'autoPayCeilingBaseUnits' => 40_000_000,
        'dailyCapBaseUnits' => 40_000_000,
        'spentTodayBaseUnits' => 39_000_000,
    ]);

    $unsure = $this->policy->evaluate(billContext([...billContextFacts($context), 'advisoryConfidence' => 0.5]));
    $certain = $this->policy->evaluate(billContext([...billContextFacts($context), 'advisoryConfidence' => 1.0]));

    // Certainty buys nothing. It moves the report past check 1 to the next
    // deterministic stop, and approves the same amount: none.
    expect($unsure->policyCode)->toBe(BillPaymentPolicy::CODE_ADVISORY_CONFIDENCE)
        ->and($unsure->approvedAmountBaseUnits)->toBe(0)
        ->and($certain->policyCode)->toBe(BillPaymentPolicy::CODE_CEILING_EXCEEDED)
        ->and($certain->approvedAmountBaseUnits)->toBe(0)
        ->and($certain->decision)->toBe(AgentDecisionType::ESCALATE);

    // The ceilings themselves are identical regardless of the advisory.
    expect($this->policy->autoPayCeilingBaseUnits($context))->toBe(BillPaymentPolicy::DEFAULT_AUTO_PAY_CEILING_BASE_UNITS)
        ->and($this->policy->dailyCapBaseUnits($context))->toBe(40_000_000);
});

test('the decision is deterministic across repeated evaluations', function (): void {
    $context = billContext();
    $first = decisionFingerprint($this->policy->evaluate($context));

    for ($run = 0; $run < 25; $run++) {
        expect(decisionFingerprint($this->policy->evaluate($context)))->toBe($first);
    }

    // A second, identically configured instance agrees with the first.
    expect(decisionFingerprint((new BillPaymentPolicy)->evaluate($context)))->toBe($first);

    // And so does an instance built from exactly the same facts.
    $rebuilt = new BillPolicyContext(...billContextFacts($context));
    expect(decisionFingerprint($this->policy->evaluate($rebuilt)))->toBe($first);
});

test('the policy never writes, and depends on no model at all', function (): void {
    DB::enableQueryLog();
    try {
        $context = billContext();
        $this->policy->evaluate($context);
        $this->policy->evaluate($context);

        expect(DB::getQueryLog())->toBe([]);
    } finally {
        DB::disableQueryLog();
    }

    // Structural proof rather than behavioural: nothing in the policy path
    // references Eloquent, the container, the clock, randomness or a provider.
    $source = implode('', array_map(
        static fn (string $path): string => (string) file_get_contents($path),
        [
            app_path('Services/BillPaymentPolicy.php'),
            app_path('DTOs/BillPolicyContext.php'),
            app_path('DTOs/BillPolicyDecision.php'),
        ],
    ));

    expect($source)->not->toContain('Illuminate\Database')
        ->and($source)->not->toContain('App\\Models')
        ->and($source)->not->toContain('DB::')
        ->and($source)->not->toContain('now()')
        ->and($source)->not->toContain('Carbon')
        ->and($source)->not->toContain('random_int')
        ->and($source)->not->toContain('rand(')
        ->and($source)->not->toContain('shuffle(')
        ->and($source)->not->toContain('Str::decide')
        ->and($source)->not->toContain('Cache::')
        ->and($source)->not->toContain('Log::')
        ->and($source)->not->toContain('config(');

    // The mirror of PolicyEvaluationResult, minus the float money fields.
    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(BillPolicyDecision::class))->getProperties(),
    );
    expect($properties)->toContain('approvedAmountBaseUnits')
        ->and($properties)->not->toContain('approvedAmount');
});

test('the decision serialises for an audit trail without a float amount', function (): void {
    $payload = $this->policy->evaluate(billContext())->toArray();

    expect($payload)->toHaveKeys([
        'decision', 'approved_amount_base_units', 'requires_human_approval',
        'policy_code', 'reasoning', 'violations', 'checks',
    ])
        ->and($payload['decision'])->toBe('auto_approve')
        ->and($payload['approved_amount_base_units'])->toBe(1_234_567)
        ->and($payload['policy_code'])->toBe(BillPaymentPolicy::CODE_AUTO_PAYMENT)
        ->and($payload)->not->toHaveKey('approved_amount');
});

/** Every fact of a context, as named-argument overrides for a fresh instance. */
function billContextFacts(BillPolicyContext $context): array
{
    return [
        'amountBaseUnits' => $context->amountBaseUnits,
        'availableBalanceBaseUnits' => $context->availableBalanceBaseUnits,
        'minimumReserveBaseUnits' => $context->minimumReserveBaseUnits,
        'autoPayCeilingBaseUnits' => $context->autoPayCeilingBaseUnits,
        'dailyCapBaseUnits' => $context->dailyCapBaseUnits,
        'spentTodayBaseUnits' => $context->spentTodayBaseUnits,
        'category' => $context->category,
        'categoryAllowListed' => $context->categoryAllowListed,
        'hasVerifiedVendorDestination' => $context->hasVerifiedVendorDestination,
        'allowedCategories' => $context->allowedCategories,
        'advisoryCategory' => $context->advisoryCategory,
        'advisoryConfidence' => $context->advisoryConfidence,
        'advisoryAnomalyFlags' => $context->advisoryAnomalyFlags,
    ];
}
