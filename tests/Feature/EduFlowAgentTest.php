<?php

declare(strict_types=1);

use App\Agents\EduFlowAgent;
use App\Enums\AssistanceStatus;
use App\Models\Approval;
use App\Models\AssistanceRequest;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Wallet;

beforeEach(function (): void {
    $this->agent = app(EduFlowAgent::class);

    $this->org = Organization::create([
        'name' => 'Agent Loop Academy',
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
        'address' => '0xagentloopwallet',
        'balance' => 25420.00,
        'status' => 'active',
    ]);

    $this->techBudget = Budget::create([
        'organization_id' => $this->org->id,
        'name' => 'Cloud Tech',
        'category' => 'tech',
        'allocated_amount' => 4000.00,
        'spent_amount' => 0.00,
        'remaining_amount' => 4000.00,
        'status' => 'active',
    ]);

    $this->aidBudget = Budget::create([
        'organization_id' => $this->org->id,
        'name' => 'Student Assistance Fund',
        'category' => 'assistance',
        'allocated_amount' => 2000.00,
        'spent_amount' => 0.00,
        'remaining_amount' => 2000.00,
        'status' => 'active',
    ]);

    $this->cloudVendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'AWS Cloud',
        'wallet_address' => '0xawscloud',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);

    $this->equipmentVendor = Vendor::create([
        'organization_id' => $this->org->id,
        'name' => 'Lab Equipment',
        'wallet_address' => '0xlabvendor',
        'status' => 'verified',
        'risk_level' => 'low',
    ]);
});

test('autonomous cycle executes auto-pay, escalations, reserve protection, and student aid', function (): void {
    // 1. Invoice 450 USDC -> Should auto pay
    $inv1 = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->cloudVendor->id,
        'budget_id' => $this->techBudget->id,
        'reference' => 'INV-LOOP-450',
        'amount' => 450.00,
        'due_date' => now()->addDays(1),
        'status' => 'pending',
    ]);

    // 2. Invoice 2500 USDC -> Exceeds auto limit -> Should escalate
    $inv2 = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->equipmentVendor->id,
        'reference' => 'INV-LOOP-2500',
        'amount' => 2500.00,
        'due_date' => now()->addDays(2),
        'status' => 'pending',
    ]);

    // 3. Invoice 18000 USDC -> Would breach reserve (25,420 - 450 - 18,000 < 10,000) -> Should hold
    $inv3 = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->equipmentVendor->id,
        'reference' => 'INV-LOOP-18000',
        'amount' => 18000.00,
        'due_date' => now()->addDays(3),
        'status' => 'pending',
    ]);

    // 4. Student assistance request
    $student = User::factory()->create();
    $aid = AssistanceRequest::factory()->create([
        'user_id' => $student->id,
        'status' => AssistanceStatus::PENDING,
        'subject' => 'Emergency book grant',
    ]);

    // RUN THE CYCLE!
    $cycleResult = $this->agent->runAutonomousCycle($this->org);

    // Verify stats
    expect($cycleResult['stats']['auto_paid'])->toBe(2) // 450 invoice + 100 aid
        ->and($cycleResult['stats']['escalated'])->toBe(1) // 2500 equipment invoice
        ->and($cycleResult['stats']['held'])->toBe(1); // 18000 reserve breach invoice

    // Verify Invoice 1 was auto paid
    expect($inv1->fresh()->status)->toBe('auto_paid');
    expect($this->techBudget->fresh()->spent_amount)->toBe(450.00);

    // Verify Invoice 2 was escalated to human approval queue
    expect($inv2->fresh()->status)->toBe('escalated');
    $approval = Approval::where('organization_id', $this->org->id)->first();
    expect($approval)->not->toBeNull()
        ->and($approval->status)->toBe('pending');

    // Verify Invoice 3 was held for reserve safety
    expect($inv3->fresh()->status)->toBe('held');

    // Verify Student Aid was resolved with Arc transaction
    $updatedAid = $aid->fresh();
    expect($updatedAid->status)->toBe(AssistanceStatus::RESOLVED)
        ->and($updatedAid->admin_notes)->toContain('Disbursed 100 USDC on Arc');

    // Verify Wallet balance was reduced accurately (25,420 - 450 - 100 = 24,870)
    expect($this->wallet->fresh()->balance)->toBe(24870.00);
});

test('finance officer can approve escalated transaction', function (): void {
    $inv = Invoice::create([
        'organization_id' => $this->org->id,
        'vendor_id' => $this->equipmentVendor->id,
        'reference' => 'INV-ESCALATE-TEST',
        'amount' => 2500.00,
        'due_date' => now()->addDays(2),
        'status' => 'pending',
    ]);

    // Trigger cycle to escalate
    $this->agent->runAutonomousCycle($this->org);

    $approval = Approval::first();
    expect($approval)->not->toBeNull();

    $financeOfficer = User::factory()->create(['name' => 'Finance Director Jane']);

    // Human approves the escalated transaction!
    $approved = $this->agent->approveEscalation($approval, $financeOfficer, 'Approved after verifying departmental budget.');

    expect($approved)->toBeTrue()
        ->and($approval->fresh()->status)->toBe('approved')
        ->and($approval->fresh()->approver_id)->toBe($financeOfficer->id)
        ->and($inv->fresh()->status)->toBe('paid');
});
