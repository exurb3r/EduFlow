<?php

declare(strict_types=1);

use App\Agents\EduFlowAgent;
use App\Enums\AssistanceStatus;
use App\Models\AcademicTerm;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\DecisionExplainer;
use Database\Seeders\EduFlowPlanSeeder;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->seed(EduFlowPlanSeeder::class);
    Role::findOrCreate('student', 'web');

    $this->org = Organization::first();

    $this->wallet = Wallet::firstOrCreate(
        ['organization_id' => $this->org->id],
        [
            'provider' => 'circle',
            'network' => 'arc',
            'address' => '0xstudentaitestwallet',
            'balance' => 25420.00,
            'status' => 'active',
        ]
    );

    $this->user = User::factory()->create();
    $this->user->assignRole('student');

    $this->student = Student::factory()->create([
        'user_id' => $this->user->id,
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95.00,
    ]);

    $this->term = AcademicTerm::factory()->create([
        'starts_on' => today()->startOfYear(),
        'ends_on' => today()->endOfYear(),
    ]);

    $this->account = TuitionAccount::factory()->create([
        'student_id' => $this->student->id,
        'academic_term_id' => $this->term->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);
});

test('qualitative hardship synthesis categorizes student medical hardship', function (): void {
    $explainer = app(DecisionExplainer::class);

    $synthesis = $explainer->synthesizeHardship('I had emergency hospital expenses and need support paying my clinic bills urgently.');

    expect($synthesis['category'])->toBe('Emergency Medical & Health Need')
        ->and($synthesis['urgency'])->toBe('high')
        ->and($synthesis['synthesis'])->toContain('Emergency Medical');
});

test('show assistance page provides rich dual-currency AI explanation when evaluated', function (): void {
    // Above the 10 USDC auto-limit but under the 50 USDC semester cap, so the
    // bounded split engages: 10 approved, 5 escalated for advisor review.
    $request = AssistanceRequest::factory()->create([
        'student_id' => $this->student->id,
        'user_id' => $this->user->id,
        'academic_term_id' => $this->term->id,
        'requested_amount' => 15_000000,
        'reason' => 'Emergency medical costs for family clinic stay.',
        'status' => AssistanceStatus::SUBMITTED,
    ]);

    app(EduFlowAgent::class)->runAutonomousCycle($this->org);

    $response = $this->actingAs($this->user)->get(route('assistance.show', $request));

    $response->assertOk()->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->component('assistance/show')
        ->where('assistanceRequest.id', $request->id)
        ->has('aiExplanation')
        ->where('aiExplanation.decision', 'partial_approval')
        ->where('aiExplanation.policy_code', 'BOUNDED_EMERGENCY_AID_V1')
        ->where('aiExplanation.split.requested_usdc', '15.00')
        ->where('aiExplanation.split.auto_approved_usdc', '10.00')
        ->where('aiExplanation.split.pending_usdc', '5.00')
        ->where('aiExplanation.hardship_category', 'Emergency Medical & Health Need')
        ->has('aiExplanation.checks', 9)
        ->has('aiExplanation.transactions')
    );
});

test('show assistance page handles un-evaluated request with null aiExplanation gracefully', function (): void {
    $request = AssistanceRequest::factory()->create([
        'student_id' => $this->student->id,
        'user_id' => $this->user->id,
        'academic_term_id' => $this->term->id,
        'requested_amount' => 50_000000,
        'status' => AssistanceStatus::SUBMITTED,
    ]);

    $response = $this->actingAs($this->user)->get(route('assistance.show', $request));

    $response->assertOk()->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->component('assistance/show')
        ->where('assistanceRequest.id', $request->id)
        ->where('aiExplanation', null)
    );
});

test('strict json schema validation rejects invalid explanation payload', function (): void {
    $explainer = app(DecisionExplainer::class);

    expect(fn () => $explainer->validateSchema([
        'decision' => 'auto_approve',
        // missing required fields
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => $explainer->validateSchema([
        'decision' => 'auto_approve',
        'policy' => 'V1',
        'explanation' => 'Test',
        'requiresHumanApproval' => false,
        'requestedAmount' => 100,
        'approvedAmount' => 200, // approved > requested
        'dual_currency' => [],
    ]))->toThrow(InvalidArgumentException::class);
});
