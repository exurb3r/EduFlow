<?php

declare(strict_types=1);

use App\Models\AcademicTerm;
use App\Models\AgentDecision;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    // Organization has a factory. AssistanceFund and AssistancePolicyVersion
    // declare HasFactory but ship no factory class, so they use the
    // query()->create() form the finance dashboard test already uses for
    // Budget rather than a static call Eloquent only exposes via __callStatic.
    // The @var is the same one FinanceSupervisorDashboardTest uses: Larastan
    // types factory()->create() as Model|Collection and will not narrow it.
    /** @var Organization $organization */
    $organization = Organization::factory()->create([
        'name' => 'Northstar Learning Center',
        'currency' => 'USDC',
        'minimum_reserve' => 10000.00,
        'max_auto_payment' => 1000.00,
        'max_daily_disbursement' => 5000.00,
        'human_approval_threshold' => 1000.00,
    ]);

    $this->org = $organization;

    AssistanceFund::query()->create([
        'organization_id' => $this->org->id,
        'name' => 'Emergency Assistance Fund',
        'balance_base_units' => 10000_000000,
        'reserve_threshold_base_units' => 5000_000000,
        'daily_budget_base_units' => 1000_000000,
        'status' => 'active',
    ]);

    AssistancePolicyVersion::query()->create([
        'version' => 'v1',
        'organization_id' => null,
        'auto_limit_base_units' => 100_000000,
        'semester_cap_base_units' => 500_000000,
        'min_attendance_rate' => 85.00,
        'required_enrollment_status' => 'enrolled',
        'required_academic_status' => 'qualified',
        'is_active' => true,
    ]);

    $this->user = User::factory()->create();
    $this->user->assignRole(Role::findOrCreate('student', 'web'));

    $this->student = Student::factory()->for($this->user, 'user')->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 92.40,
    ]);

    $this->term = AcademicTerm::factory()->create([
        'name' => 'Term 2 · AY 2026',
        'starts_on' => today()->subMonths(2),
        'ends_on' => today()->addMonths(2),
    ]);

    TuitionAccount::factory()->for($this->student)->for($this->term, 'academicTerm')->create([
        'total_amount' => 300000000,
        'paid_amount' => 17500000,
    ]);
});

it('shows standing, eligibility and financial position to a student', function (): void {
    $this->actingAs($this->user)
        ->get(route('student.profile'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('student/profile')
            ->where('student.student_number', $this->student->student_number)
            ->where('standing.academic_status', 'qualified')
            ->where('standing.attendance_rate', '92.40')
            ->where('standing.min_attendance_rate', '85.00')
            ->where('eligibility.eligible', true)
            ->where('eligibility.eligible_amount_base_units', '100000000')
            ->where('tuition.total_amount', '300000000')
            ->where('tuition.paid_amount', '17500000')
            ->where('tuition.remaining_amount', '282500000')
            ->where('assistance.auto_limit_base_units', '100000000')
            ->where('organization.name', 'Northstar Learning Center')
        );
});

it('reports term progress within the term bounds', function (): void {
    $response = $this->actingAs($this->user)->get(route('student.profile'));

    $progress = $response->viewData('page')['props']['term']['progress'];

    expect($progress)->toBeGreaterThan(30)->toBeLessThan(70);
});

it('clamps progress to zero on the opening day of a term', function (): void {
    $this->term->update([
        'starts_on' => today(),
        'ends_on' => today()->addMonths(5),
    ]);

    $response = $this->actingAs($this->user)->get(route('student.profile'));

    expect($response->viewData('page')['props']['term']['progress'])->toBe(0);
});

it('clamps progress to one hundred on the closing day of a term', function (): void {
    $this->term->update([
        'starts_on' => today()->subMonths(5),
        'ends_on' => today(),
    ]);

    $response = $this->actingAs($this->user)->get(route('student.profile'));

    expect($response->viewData('page')['props']['term']['progress'])->toBe(100);
});

it('writes no decisions and files no requests', function (): void {
    $this->actingAs($this->user)->get(route('student.profile'))->assertOk();

    expect(AgentDecision::query()->count())->toBe(0)
        ->and(AssistanceRequest::query()->count())->toBe(0);
});

it('refuses non-students', function (): void {
    $staff = User::factory()->create();
    $staff->assignRole(Role::findOrCreate('finance_officer', 'web'));

    $this->actingAs($staff)->get(route('student.profile'))->assertForbidden();
});

it('requires authentication', function (): void {
    $this->get(route('student.profile'))->assertRedirect(route('login'));
});
