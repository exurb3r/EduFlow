<?php

declare(strict_types=1);

use App\Actions\ApproveEscalatedRequest;
use App\Agents\EduFlowAgent;
use App\Enums\AssistanceStatus;
use App\Filament\Resources\AssistanceRequests\AssistanceRequestResource;
use App\Filament\Resources\AssistanceRequests\Pages\ListAssistanceRequests;
use App\Filament\Resources\AssistanceRequests\Pages\ViewAssistanceRequest;
use App\Models\AcademicTerm;
use App\Models\Approval;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Models\Wallet;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Notification::fake();

    foreach (['admin', 'finance_officer', 'super_admin'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $this->officer = User::factory()->create()->assignRole('finance_officer');

    $this->org = Organization::create([
        'name' => 'Escalation Academy',
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
        'address' => '0xescalationtreasury',
        'balance' => 25420.00,
        'status' => 'active',
    ]);

    $this->fund = AssistanceFund::create([
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

    $this->student = Student::factory()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    $this->term = AcademicTerm::factory()->create();

    TuitionAccount::factory()->create([
        'student_id' => $this->student->id,
        'academic_term_id' => $this->term->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);
});

function makeSplitRequest(array $attributes = []): AssistanceRequest
{
    return AssistanceRequest::factory()->create(array_merge([
        'student_id' => test()->student->id,
        'user_id' => test()->student->user_id,
        'academic_term_id' => test()->term->id,
        'requested_amount' => 150_000000,
        'status' => AssistanceStatus::SUBMITTED,
        'subject' => 'Emergency assistance',
    ], $attributes));
}

test('auto-approved requests expose no escalated remainder', function (): void {
    $request = makeSplitRequest(['requested_amount' => 80_000000]);
    $this->agent = app(EduFlowAgent::class);
    $this->agent->runAutonomousCycle($this->org);

    $request = $request->fresh();

    expect($request->pendingReviewBaseUnits())->toBe(0)
        ->and(AssistanceRequestResource::splitSummary($request))->toContain('Auto-approved 80.00 USDC')
        ->and(AssistanceRequestResource::splitSummary($request))->not->toContain('Awaiting approval');
});

test('split decision records locked quote and dual currency summary', function (): void {
    $request = makeSplitRequest();
    app(EduFlowAgent::class)->runAutonomousCycle($this->org);

    $request = $request->fresh();
    $decision = $request->latestAgentDecision();

    expect($decision)->not->toBeNull()
        ->and($decision->requires_approval)->toBeTrue()
        ->and($request->pendingReviewBaseUnits())->toBe(50_000000);

    $summary = AssistanceRequestResource::splitSummary($request);
    expect($summary)->toContain('Auto-approved 100.00 USDC')
        ->and($summary)->toContain('₱5,750.00')
        ->and($summary)->toContain('Awaiting approval 50.00 USDC')
        ->and($summary)->toContain('₱2,875.00');

    $quote = AssistanceRequestResource::lockedQuoteSummary($decision);
    expect($quote)->toContain('1 USDC = 5750 minor PHP')
        ->and($quote)->toContain('fallback');

    $checks = AssistanceRequestResource::checkSummary($decision);
    expect($checks)->toContain('✓ enrolled')
        ->and($checks)->toContain('✓ attendance ok')
        ->and($checks)->toContain('✗ within auto limit');
});

test('finance officer approves remainder through the panel action', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    $request = makeSplitRequest();
    app(EduFlowAgent::class)->runAutonomousCycle($this->org);

    $this->actingAs($this->officer);
    $request = $request->fresh();

    // The agent proposed this payout but did not make it; only the officer's
    // approval moves funds, and only the amount actually approved.
    expect($this->wallet->fresh()->balance)->toBe(25420.00)
        ->and($this->fund->fresh()->balance_base_units)->toBe(10000_000000);

    Livewire::test(ListAssistanceRequests::class)
        ->assertTableActionVisible('approve_escalated', record: $request)
        ->assertTableActionVisible('reject_escalated', record: $request)
        ->callTableAction('approve_escalated', $request, data: ['comment' => 'Verified hardship.'])
        ->assertHasNoActionErrors();

    $request = $request->fresh();
    $decision = $request->latestAgentDecision();

    // The agent proposed 150 but paid none of it. Only the officer's approval
    // moves funds, and only the escalated portion it actually covers.
    expect($request->status instanceof AssistanceStatus ? $request->status->value : $request->status)->toBe(AssistanceStatus::RESOLVED->value)
        ->and($this->wallet->fresh()->balance)->toBe(25370.00)
        ->and($this->fund->fresh()->balance_base_units)->toBe(9950_000000)
        ->and($decision->approved_amount)->toBe(150.00)
        ->and(Approval::where('agent_decision_id', $decision->id)->value('status'))->toBe('approved');

    $settlement = Transaction::where('reference_type', AssistanceRequest::class)
        ->where('reference_id', $request->id)
        ->get();

    // Exactly one settlement, the one the officer authorized. Before this
    // change there were two: an automatic 100 and a human-approved 50.
    expect($settlement)->toHaveCount(1)
        ->and($settlement->sum('amount'))->toBe(50.00)
        ->and($settlement->first()->metadata['human_override'] ?? null)->toBeTrue();
});

test('rejecting the remainder closes the request without moving more funds', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    $request = makeSplitRequest();
    app(EduFlowAgent::class)->runAutonomousCycle($this->org);

    $this->actingAs($this->officer);
    $request = $request->fresh();

    Livewire::test(ListAssistanceRequests::class)
        ->callTableAction('reject_escalated', $request)
        ->assertHasNoActionErrors();

    $request = $request->fresh();
    $decision = $request->latestAgentDecision();

    // Rejecting moves nothing at all: the agent proposed a payout and neither
    // the proposal nor the rejection touched the treasury.
    expect($request->status instanceof AssistanceStatus ? $request->status->value : $request->status)->toBe(AssistanceStatus::CLOSED->value)
        ->and($this->wallet->fresh()->balance)->toBe(25420.00)
        ->and($this->fund->fresh()->balance_base_units)->toBe(10000_000000)
        ->and($decision->status)->toBe('rejected')
        ->and(Approval::where('agent_decision_id', $decision->id)->value('status'))->toBe('rejected');
});

test('approve action is hidden once nothing remains pending', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    $request = makeSplitRequest();
    app(EduFlowAgent::class)->runAutonomousCycle($this->org);

    $this->actingAs($this->officer);
    $request = $request->fresh();

    // Visible while the remainder is still outstanding.
    Livewire::test(ListAssistanceRequests::class)
        ->assertTableActionVisible('approve_escalated', record: $request);

    app(ApproveEscalatedRequest::class)->handle(
        request: $request,
        decision: $request->latestAgentDecision(),
        approver: $this->officer,
        fund: $this->fund,
    );

    $fresh = $request->fresh();

    expect($fresh->pendingReviewBaseUnits())->toBe(0);

    Livewire::test(ListAssistanceRequests::class)
        ->assertTableActionHidden('approve_escalated', record: $fresh)
        ->assertTableActionHidden('reject_escalated', record: $fresh);
});

test('view page surfaces the agent decision section with split and checks', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    $request = makeSplitRequest();
    app(EduFlowAgent::class)->runAutonomousCycle($this->org);
    $this->actingAs($this->officer);

    Livewire::test(ViewAssistanceRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('EduFlow AI decision')
        ->assertSee('Partial Approval')
        ->assertSee('BOUNDED_EMERGENCY_AID_V1')
        ->assertSee('1 USDC = 5750 minor PHP')
        ->assertSee('Awaiting approval 50.00 USDC')
        ->assertActionVisible('approve_escalated');
});

test('auto-approved request view shows no pending approval amount', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('finance'));
    $request = makeSplitRequest(['requested_amount' => 80_000000]);
    app(EduFlowAgent::class)->runAutonomousCycle($this->org);
    $this->actingAs($this->officer);

    Livewire::test(ViewAssistanceRequest::class, ['record' => $request->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Auto Approved')
        ->assertSee('Auto-approved 80.00 USDC')
        ->assertDontSee('Awaiting approval');
});

test('navigable filter isolates requests awaiting human approval', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('finance'));

    $split = makeSplitRequest();
    $auto = makeSplitRequest(['requested_amount' => 80_000000]);

    app(EduFlowAgent::class)->runAutonomousCycle($this->org);

    // Created after the cycle, so the agent never evaluated it: no decision,
    // nothing awaiting a human.
    $untouched = makeSplitRequest();

    $this->actingAs($this->officer);

    $split = $split->fresh();
    $auto = $auto->fresh();

    expect($split->pendingReviewBaseUnits())->toBe(50_000000)
        ->and($auto->pendingReviewBaseUnits())->toBe(0)
        ->and($untouched->pendingReviewBaseUnits())->toBe(0);

    Livewire::test(ListAssistanceRequests::class)
        ->filterTable('awaiting_human_approval', 'yes')
        ->assertCanSeeTableRecords([$split])
        ->assertCanNotSeeTableRecords([$auto, $untouched])
        ->filterTable('awaiting_human_approval', 'no')
        ->assertCanNotSeeTableRecords([$split])
        ->assertCanSeeTableRecords([$auto, $untouched]);
});
