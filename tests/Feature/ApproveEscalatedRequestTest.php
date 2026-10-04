<?php

declare(strict_types=1);

use App\Actions\ApproveEscalatedRequest;
use App\Enums\AgentDecisionType;
use App\Enums\AssistanceCategory;
use App\Enums\AssistanceStatus;
use App\Models\AcademicTerm;
use App\Models\AgentDecision;
use App\Models\AssistanceFund;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Models\Wallet;

beforeEach(function (): void {
    $this->org = Organization::create([
        'name' => 'EduFlow Test College',
        'currency' => 'USDC',
        'minimum_reserve' => 1000.00,
        'max_auto_payment' => 500.00,
        'max_daily_disbursement' => 2000.00,
        'human_approval_threshold' => 500.00,
    ]);

    $this->wallet = Wallet::create([
        'organization_id' => $this->org->id,
        'provider' => 'circle',
        'network' => 'arc',
        'address' => '0xtreasury00000000000000000000000000000001',
        'balance' => 5000.00,
        'status' => 'active',
    ]);

    $this->fund = AssistanceFund::create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 1000_000000,
        'daily_budget_base_units' => 5000_000000,
        'status' => 'active',
    ]);

    $this->approver = User::factory()->create(['name' => 'Dr. Financial Aid']);
    $this->term = AcademicTerm::factory()->create();
});

test('throws exception and fails closed when student has no payout address', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    $request = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'academic_term_id' => $this->term->id,
        'category' => AssistanceCategory::GENERAL,
        'requested_amount' => 300_000000,
    ]);

    $decision = AgentDecision::create([
        'organization_id' => $this->org->id,
        'action_type' => 'student_assistance',
        'reference_type' => AssistanceRequest::class,
        'reference_id' => $request->id,
        'decision' => AgentDecisionType::PARTIAL_APPROVAL,
        'reasoning_summary' => 'Partial approval test 1',
        'policy_checked' => 'TEST_POLICY_V1',
        'requested_amount' => 300.00,
        'approved_amount' => 100.00,
        'requires_approval' => true,
        'status' => 'escalated',
        'input_snapshot' => [
            'approved_base_units' => 100_000000,
            'locked_quote' => [],
        ],
    ]);

    expect(fn () => app(ApproveEscalatedRequest::class)->handle(
        request: $request,
        decision: $decision,
        approver: $this->approver,
        fund: $this->fund,
    ))->toThrow(InvalidArgumentException::class, 'Student does not have a valid payout address on file.');

    expect($decision->fresh()->status)->toBe('escalated')
        ->and($request->fresh()->status)->toBe(AssistanceStatus::SUBMITTED);
});

test('throws exception when student has invalid payout address format', function (): void {
    $student = Student::factory()->withInvalidPayoutAddress()->create();
    $request = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'academic_term_id' => $this->term->id,
        'category' => AssistanceCategory::GENERAL,
        'requested_amount' => 300_000000,
    ]);

    $decision = AgentDecision::create([
        'organization_id' => $this->org->id,
        'action_type' => 'student_assistance',
        'reference_type' => AssistanceRequest::class,
        'reference_id' => $request->id,
        'decision' => AgentDecisionType::PARTIAL_APPROVAL,
        'reasoning_summary' => 'Partial approval test 2',
        'policy_checked' => 'TEST_POLICY_V1',
        'requested_amount' => 300.00,
        'approved_amount' => 100.00,
        'requires_approval' => true,
        'status' => 'escalated',
        'input_snapshot' => [
            'approved_base_units' => 100_000000,
            'locked_quote' => [],
        ],
    ]);

    expect(fn () => app(ApproveEscalatedRequest::class)->handle(
        request: $request,
        decision: $decision,
        approver: $this->approver,
        fund: $this->fund,
    ))->toThrow(InvalidArgumentException::class, 'Student does not have a valid payout address on file.');
});

test('approves escalated remainder and executes transfer when student has valid payout address', function (): void {
    $validAddress = '0x1234567890123456789012345678901234567890';
    $student = Student::factory()->create(['payout_address' => $validAddress]);
    $request = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'academic_term_id' => $this->term->id,
        'category' => AssistanceCategory::GENERAL,
        'requested_amount' => 300_000000,
    ]);

    $decision = AgentDecision::create([
        'organization_id' => $this->org->id,
        'action_type' => 'student_assistance',
        'reference_type' => AssistanceRequest::class,
        'reference_id' => $request->id,
        'decision' => AgentDecisionType::PARTIAL_APPROVAL,
        'reasoning_summary' => 'Partial approval test 3',
        'policy_checked' => 'TEST_POLICY_V1',
        'requested_amount' => 300.00,
        'approved_amount' => 100.00,
        'requires_approval' => true,
        'status' => 'escalated',
        'input_snapshot' => [
            'approved_base_units' => 100_000000,
            'locked_quote' => [],
        ],
    ]);

    $approval = app(ApproveEscalatedRequest::class)->handle(
        request: $request,
        decision: $decision,
        approver: $this->approver,
        fund: $this->fund,
        comment: 'Verified need with student.',
    );

    expect($approval->status)->toBe('approved')
        ->and($approval->comment)->toBe('Verified need with student.')
        ->and($decision->fresh()->status)->toBe('executed')
        ->and((float) $decision->fresh()->approved_amount)->toBe(300.00)
        ->and($request->fresh()->status)->toBe(AssistanceStatus::RESOLVED)
        ->and($this->wallet->fresh()->balance)->toBe(4800.00) // 5000 - 200
        ->and($this->fund->fresh()->balance_base_units)->toBe(9800_000000); // 10000 - 200
});

test('settles escalated remainder to tuition ledger without payout address', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();
    $account = TuitionAccount::factory()->create([
        'student_id' => $student->id,
        'academic_term_id' => $this->term->id,
        'total_amount' => 500_000000,
        'paid_amount' => 100_000000,
    ]);

    $request = AssistanceRequest::factory()->create([
        'student_id' => $student->id,
        'user_id' => $student->user_id,
        'academic_term_id' => $this->term->id,
        'category' => AssistanceCategory::FINANCIAL,
        'requested_amount' => 250_000000,
    ]);

    $decision = AgentDecision::create([
        'organization_id' => $this->org->id,
        'action_type' => 'student_assistance',
        'reference_type' => AssistanceRequest::class,
        'reference_id' => $request->id,
        'decision' => AgentDecisionType::PARTIAL_APPROVAL,
        'reasoning_summary' => 'Partial approval test 4',
        'policy_checked' => 'TEST_POLICY_V1',
        'requested_amount' => 250.00,
        'approved_amount' => 100.00,
        'requires_approval' => true,
        'status' => 'escalated',
        'input_snapshot' => [
            'approved_base_units' => 100_000000,
            'locked_quote' => [],
        ],
    ]);

    $approval = app(ApproveEscalatedRequest::class)->handle(
        request: $request,
        decision: $decision,
        approver: $this->approver,
        fund: $this->fund,
        comment: 'Offset tuition balance directly.',
    );

    expect($approval->status)->toBe('approved')
        ->and($account->fresh()->paid_amount)->toBe(250_000000) // 100 + 150 remainder
        ->and($decision->fresh()->status)->toBe('executed')
        ->and($request->fresh()->status)->toBe(AssistanceStatus::RESOLVED)
        ->and($request->fresh()->admin_notes)->toContain('applied to tuition ledger');
});
