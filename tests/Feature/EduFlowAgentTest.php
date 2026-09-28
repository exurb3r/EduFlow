<?php

declare(strict_types=1);

use App\Actions\ApproveEscalatedRequest;
use App\Agents\EduFlowAgent;
use App\Enums\AgentDecisionType;
use App\Enums\AssistanceStatus;
use App\Models\AcademicTerm;
use App\Models\AgentDecision;
use App\Models\Approval;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Student;
use App\Models\TuitionAccount;
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

    // 4. Student assistance request (eligible: enrolled, qualified, 95% attendance)
    $eligibleStudent = Student::factory()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    TuitionAccount::factory()->create([
        'student_id' => $eligibleStudent->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $aid = AssistanceRequest::factory()->create([
        'student_id' => $eligibleStudent->id,
        'user_id' => $eligibleStudent->user_id,
        'requested_amount' => 100_000000,
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

test('autonomous cycle splits 150 USDC aid into 100 auto plus 50 escalated', function (): void {
    $student = Student::factory()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    TuitionAccount::factory()->create([
        'student_id' => $student->id,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    $fund = AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $aid = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'requested_amount' => 150_000000,
        'status' => AssistanceStatus::SUBMITTED,
        'subject' => 'Emergency assistance 150',
    ]);

    $result = $this->agent->runAutonomousCycle($this->org);

    $updated = $aid->fresh();

    expect($updated->status instanceof AssistanceStatus ? $updated->status->value : $updated->status)->toBe(AssistanceStatus::IN_PROGRESS->value)
        ->and($result['stats']['auto_paid'])->toBe(1)
        ->and($result['stats']['escalated'])->toBe(1)
        ->and($this->wallet->fresh()->balance)->toBe(25320.00)
        ->and($fund->fresh()->balance_base_units)->toBe(9900_000000);

    $decision = AgentDecision::where('reference_id', $aid->id)
        ->where('reference_type', AssistanceRequest::class)
        ->first();

    expect($decision)->not->toBeNull()
        ->and($decision->decision)->toBe(AgentDecisionType::PARTIAL_APPROVAL)
        ->and($decision->input_snapshot['locked_quote']['quote'])->toBe('PHP');

    $approval = Approval::where('agent_decision_id', $decision->id)->first();
    expect($approval)->not->toBeNull()->and($approval->status)->toBe('pending');

    $officer = User::factory()->create(['name' => 'Finance Officer']);
    app(ApproveEscalatedRequest::class)->handle($aid, $decision, $officer, $fund, 'Remainder approved.');

    expect($aid->fresh()->status instanceof AssistanceStatus ? $aid->fresh()->status->value : $aid->fresh()->status)->toBe(AssistanceStatus::RESOLVED->value)
        ->and($this->wallet->fresh()->balance)->toBe(25270.00)
        ->and($approval->fresh()->status)->toBe('approved');
});
