<?php

declare(strict_types=1);

use App\Actions\ApproveEscalatedRequest;
use App\Actions\EvaluateAssistancePolicy;
use App\Enums\AgentDecisionType;
use App\Enums\AssistanceStatus;
use App\Enums\CurrencyCode;
use App\Models\AcademicTerm;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Models\Wallet;

beforeEach(function (): void {
    $this->org = Organization::create([
        'name' => 'Northstar Learning Center',
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
        'address' => '0xtreasury'.bin2hex(random_bytes(8)),
        'balance' => 25420.00,
        'status' => 'active',
    ]);

    $this->fund = AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 2400_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    // Raise fund balance so reserve checks pass in tests (fund is separate from treasury).
    $this->fund->update(['balance_base_units' => 10000_000000]);

    $this->policy = AssistancePolicyVersion::create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $this->term = AcademicTerm::factory()->create();
});

function makeEligibleStudent(): Student
{
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

    return $student;
}

test('auto-approves requests within 100 USDC limit', function (): void {
    $student = makeEligibleStudent();

    $request = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'academic_term_id' => $this->term->id,
        'requested_amount' => 80_000000,
        'status' => 'submitted',
    ]);

    $out = app(EvaluateAssistancePolicy::class)->handle($request, $this->fund, $this->policy, CurrencyCode::PHP);

    expect($out['result']->decision)->toBe(AgentDecisionType::AUTO_APPROVE)
        ->and($out['result']->requiresHumanApproval)->toBeFalse()
        ->and($out['quote']['quote'])->toBe('PHP')
        ->and($out['decision']->policy_checked)->toBe('STUDENT_ASSISTANCE_AUTO_V1');
});

test('splits 150 USDC into 100 auto plus 50 escalated', function (): void {
    $student = makeEligibleStudent();

    $request = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'academic_term_id' => $this->term->id,
        'requested_amount' => 150_000000,
        'status' => 'submitted',
    ]);

    $out = app(EvaluateAssistancePolicy::class)->handle($request, $this->fund, $this->policy);

    expect($out['result']->decision)->toBe(AgentDecisionType::PARTIAL_APPROVAL)
        ->and($out['result']->approvedAmount)->toBe(100.00)
        ->and($out['result']->requiresHumanApproval)->toBeTrue();
});

test('rejects when attendance is below threshold', function (): void {
    $student = makeEligibleStudent();
    $student->update(['attendance_rate' => 60.00]);

    $request = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'academic_term_id' => $this->term->id,
        'requested_amount' => 50_000000,
        'status' => 'submitted',
    ]);

    $out = app(EvaluateAssistancePolicy::class)->handle($request, $this->fund, $this->policy);

    expect($out['result']->decision)->toBe(AgentDecisionType::REJECT);
});

test('approves escalated remainder via second transfer', function (): void {
    $student = makeEligibleStudent();

    $request = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'academic_term_id' => $this->term->id,
        'requested_amount' => 150_000000,
        'status' => 'in_progress',
    ]);

    $eval = app(EvaluateAssistancePolicy::class)->handle($request, $this->fund, $this->policy);
    $approver = User::factory()->create();

    $approval = app(ApproveEscalatedRequest::class)->handle($request, $eval['decision'], $approver, $this->fund, 'Approved for demo');

    expect($approval->status)->toBe('approved')
        ->and($request->fresh()->status instanceof AssistanceStatus ? $request->fresh()->status->value : $request->fresh()->status)->toBe('resolved');
});
