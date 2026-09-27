<?php

declare(strict_types=1);

use App\Enums\AgentDecisionType;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Vendor;
use App\Models\Wallet;
use App\Services\FinancialPolicyEngine;

beforeEach(function (): void {
    $this->engine = app(FinancialPolicyEngine::class);

    $this->org = Organization::create([
        'name' => 'Demo Academy',
        'currency' => 'USDC',
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    $this->wallet = Wallet::create([
        'organization_id' => $this->org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0x1234567890abcdef',
        'balance' => 25420.00,
        'status' => 'active',
    ]);

    $this->budget = Budget::create([
        'organization_id' => $this->org->id,
        'name' => 'Technology',
        'category' => 'tech',
        'allocated_amount' => 5000.00,
        'spent_amount' => 0.00,
        'remaining_amount' => 5000.00,
        'status' => 'active',
    ]);

    $this->vendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'Cloud Host',
        'wallet_address' => '0xvendor123',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);
});

test('auto approves invoice within budget and below auto-payment threshold', function (): void {
    $invoice = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->vendor->id,
        'budget_id' => $this->budget->id,
        'reference' => 'INV-TEST-450',
        'amount' => 450.00,
        'due_date' => now()->addDays(2),
        'status' => 'pending',
    ]);

    $result = $this->engine->evaluateInvoice($invoice, $this->wallet);

    expect($result->decision)->toBe(AgentDecisionType::AUTO_APPROVE)
        ->and($result->approvedAmount)->toBe(450.00)
        ->and($result->requiresHumanApproval)->toBeFalse()
        ->and($result->violations)->toBeEmpty();
});

test('escalates invoice exceeding the autonomous payment threshold', function (): void {
    $invoice = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->vendor->id,
        'budget_id' => $this->budget->id,
        'reference' => 'INV-TEST-2500',
        'amount' => 2500.00,
        'due_date' => now()->addDays(3),
        'status' => 'pending',
    ]);

    $result = $this->engine->evaluateInvoice($invoice, $this->wallet);

    expect($result->decision)->toBe(AgentDecisionType::ESCALATE)
        ->and($result->requiresHumanApproval)->toBeTrue()
        ->and($result->policyCode)->toBe('HIGH_VALUE_DISBURSEMENT_V1');
});

test('holds invoice that would breach the minimum treasury reserve', function (): void {
    $largeBudget = Budget::create([
        'organization_id' => $this->org->id,
        'name' => 'Major CapEx',
        'category' => 'capex',
        'allocated_amount' => 50000.00,
        'spent_amount' => 0.00,
        'remaining_amount' => 50000.00,
        'status' => 'active',
    ]);

    $invoice = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->vendor->id,
        'budget_id' => $largeBudget->id,
        'reference' => 'INV-TEST-18000',
        'amount' => 18000.00, // 25,420 - 18,000 = 7,420 < 10,000 reserve
        'due_date' => now()->addDays(1),
        'status' => 'pending',
    ]);

    $result = $this->engine->evaluateInvoice($invoice, $this->wallet);

    expect($result->decision)->toBe(AgentDecisionType::HOLD)
        ->and($result->requiresHumanApproval)->toBeTrue()
        ->and($result->policyCode)->toBe('TREASURY_RESERVE_SAFETY_V1');
});

test('escalates invoice if vendor is unverified', function (): void {
    $unverifiedVendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'Shady Supplier',
        'wallet_address' => '0xunknown',
        'status' => 'pending',
        'risk_level' => 'high',
    ]);

    $invoice = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $unverifiedVendor->id,
        'budget_id' => $this->budget->id,
        'reference' => 'INV-UNVERIFIED-200',
        'amount' => 200.00,
        'due_date' => now()->addDays(1),
        'status' => 'pending',
    ]);

    $result = $this->engine->evaluateInvoice($invoice, $this->wallet);

    expect($result->decision)->toBe(AgentDecisionType::ESCALATE)
        ->and($result->policyCode)->toBe('VENDOR_COMPLIANCE_V1');
});

test('rejects invoice if allocated budget is exhausted', function (): void {
    $smallBudget = Budget::create([
        'organization_id' => $this->org->id,
        'name' => 'Snacks',
        'category' => 'food',
        'allocated_amount' => 100.00,
        'spent_amount' => 90.00,
        'remaining_amount' => 10.00,
        'status' => 'active',
    ]);

    $invoice = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->vendor->id,
        'budget_id' => $smallBudget->id,
        'reference' => 'INV-EXHAUSTED-50',
        'amount' => 50.00,
        'due_date' => now()->addDays(1),
        'status' => 'pending',
    ]);

    $result = $this->engine->evaluateInvoice($invoice, $this->wallet);

    expect($result->decision)->toBe(AgentDecisionType::REJECT)
        ->and($result->policyCode)->toBe('BUDGET_EXHAUSTION_V1');
});

test('student assistance auto approves under 100 USDC and partial approves over 100 USDC', function (): void {
    // 80 USDC -> Auto approved
    $res1 = $this->engine->evaluateStudentAssistance(80.00, $this->org, $this->wallet);
    expect($res1->decision)->toBe(AgentDecisionType::AUTO_APPROVE)
        ->and($res1->approvedAmount)->toBe(80.00)
        ->and($res1->requiresHumanApproval)->toBeFalse();

    // 150 USDC -> Partial approval: 100 approved, 50 escalated
    $res2 = $this->engine->evaluateStudentAssistance(150.00, $this->org, $this->wallet);
    expect($res2->decision)->toBe(AgentDecisionType::PARTIAL_APPROVAL)
        ->and($res2->approvedAmount)->toBe(100.00)
        ->and($res2->requiresHumanApproval)->toBeTrue();
});
