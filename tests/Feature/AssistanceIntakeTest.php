<?php

declare(strict_types=1);

use App\Enums\AssistanceStatus;
use App\Models\AcademicTerm;
use App\Models\AssistanceRequest;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-09-15 12:00:00'));

    foreach (['student', 'finance_officer', 'admin', 'super_admin', 'user'] as $role) {
        Role::findOrCreate($role, 'web');
    }

    $this->student = Student::factory()->create();
    $this->student->user->assignRole('student');
    $this->term = AcademicTerm::factory()->create();
    $this->account = TuitionAccount::factory()->for($this->student)->for($this->term)->create([
        'total_amount' => 300000000,
        'paid_amount' => 25000000,
    ]);
    $this->payload = [
        'submission_key' => (string) Str::uuid(),
        'type' => 'emergency',
        'requested_amount' => '150.000001',
        'reason' => 'Private emergency medical expenses for my family.',
    ];
});

test('intake routes require authentication and verified email', function (bool $unverified): void {
    $request = AssistanceRequest::factory()->for($this->student)->for($this->term)->create();

    if ($unverified) {
        $user = User::factory()->unverified()->create()->assignRole('student');
        Student::factory()->for($user)->create();
        $this->actingAs($user);
    }

    $redirect = route($unverified ? 'verification.notice' : 'login');

    foreach (['dashboard', 'student.dashboard', 'assistance.create'] as $route) {
        $this->get(route($route))->assertRedirect($redirect);
    }

    $this->get(route('assistance.show', $request))->assertRedirect($redirect);
    $this->post(route('assistance.store'), $this->payload)->assertRedirect($redirect);

    expect(AssistanceRequest::count())->toBe(1)
        ->and(Activity::where('event', 'submitted')->count())->toBe(0);
})->with(['guest' => false, 'unverified' => true]);

test('dashboard routes students and staff to their role destinations', function (string $role, string $destination): void {
    $user = User::factory()->create()->assignRole($role);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route($destination));
})->with([
    ['student', 'student.dashboard'],
    ['finance_officer', 'filament.finance.resources.assistance-requests.index'],
    ['admin', 'filament.finance.resources.assistance-requests.index'],
    ['super_admin', 'filament.finance.resources.assistance-requests.index'],
]);

test('dashboard keeps the generic page for users without a privileged role', function (?string $role): void {
    $user = User::factory()->create();

    if ($role !== null) {
        $user->assignRole($role);
    }

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('dashboard'));
    $this->get(route('student.dashboard'))->assertForbidden();
})->with(['no role' => null, 'ordinary role' => 'user']);

test('student role takes precedence over staff dashboard routing', function (): void {
    $this->student->user->assignRole('finance_officer');

    $this->actingAs($this->student->user)->get(route('dashboard'))
        ->assertRedirect(route('student.dashboard'));
});

test('student dashboard shows only owned requests and current tuition account', function (): void {
    $owned = AssistanceRequest::factory()->for($this->student)->for($this->term)->create();
    AssistanceRequest::factory()->create();
    TuitionAccount::factory()->for($this->student)->for(AcademicTerm::factory()->create([
        'starts_on' => today()->subYear(), 'ends_on' => today()->subDay(),
    ]))->create();

    $this->actingAs($this->student->user)->get(route('student.dashboard'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('student/dashboard')
        ->where('student.name', $this->student->user->name)
        ->where('student.student_number', $this->student->student_number)
        ->where('student.program', $this->student->program)
        ->where('student.year_level', $this->student->year_level)
        ->where('tuitionAccount.term', $this->term->name)
        ->where('tuitionAccount.total_amount', '300000000')
        ->where('tuitionAccount.paid_amount', '25000000')
        ->where('tuitionAccount.remaining_amount', '275000000')
        ->where('canRequest', true)
        ->has('requests', 1)
        ->where('requests.0.id', $owned->id)
        ->where('requests.0.requested_amount', (string) $owned->requested_amount)
        ->missing('requests.0.reason'));
});

test('students can view their own request but cannot access another students details', function (): void {
    $owned = AssistanceRequest::factory()->for($this->student)->for($this->term)->create();
    $other = AssistanceRequest::factory()->create();

    $this->actingAs($this->student->user)->get(route('assistance.show', $owned))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('assistance/show')
        ->where('assistanceRequest.id', $owned->id)
        ->where('assistanceRequest.reason', $owned->reason)
        ->where('assistanceRequest.type', 'emergency')
        ->where('assistanceRequest.status', 'submitted')
        ->where('assistanceRequest.requested_amount', (string) $owned->requested_amount)
        ->where('assistanceRequest.term', $this->term->name)
        ->where('assistanceRequest.submitted_at', $owned->submitted_at->toIso8601String()));
    $this->get(route('assistance.show', $other))->assertForbidden()->assertDontSee($other->reason);
});

test('creation requires both the student role and a linked student', function (bool $hasRole, bool $hasStudent): void {
    $user = User::factory()->create();

    if ($hasRole) {
        $user->assignRole('student');
    }

    if ($hasStudent) {
        $student = Student::factory()->for($user)->create();
        TuitionAccount::factory()->for($student)->for($this->term)->create();
    }

    $this->actingAs($user)->get(route('assistance.create'))->assertForbidden();
    $this->postJson(route('assistance.store'), $this->payload)->assertForbidden();

    expect(AssistanceRequest::count())->toBe(0)
        ->and(Activity::where('event', 'submitted')->count())->toBe(0);
})->with([
    'role without linked student' => [true, false],
    'linked student without role' => [false, true],
    'neither role nor student' => [false, false],
]);

test('create page supplies a fresh submission key and submission preserves exact amounts and tuition', function (): void {
    $this->actingAs($this->student->user);
    $form = $this->get(route('assistance.create'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('assistance/create')
            ->where('term', $this->term->name)
            ->where('submissionKey', fn (string $key): bool => Str::isUuid($key)));
    $key = $form->inertiaProps('submissionKey');
    $this->get(route('assistance.create'))->assertInertia(fn (Assert $page) => $page
        ->where('submissionKey', fn (string $next): bool => Str::isUuid($next) && $next !== $key));
    $before = $this->account->getAttributes();

    $response = $this->post(route('assistance.store'), [...$this->payload, 'submission_key' => $key]);
    $request = AssistanceRequest::sole();

    $response->assertSessionHasNoErrors()->assertRedirect(route('assistance.show', $request));
    expect($request->student_id)->toBe($this->student->id)
        ->and($request->academic_term_id)->toBe($this->term->id)
        ->and($request->submission_key)->toBe($key)
        ->and($request->requested_amount)->toBe(150000001)
        ->and($request->type)->toBe('emergency')
        ->and($request->status instanceof AssistanceStatus ? $request->status->value : $request->status)->toBe('submitted')
        ->and($request->reason)->toBe($this->payload['reason'])
        ->and($request->submitted_at->equalTo(now()))->toBeTrue()
        ->and($this->account->fresh()->getAttributes())->toEqual($before)
        ->and($this->account->fresh()->remainingAmount())->toBe(275000000);

    $audit = Activity::where('log_name', 'education')->sole();
    expect($audit->event)->toBe('submitted')
        ->and($audit->description)->toBe('Assistance request submitted')
        ->and($audit->subject->is($request))->toBeTrue()
        ->and($audit->causer->is($this->student->user))->toBeTrue()
        ->and($audit->properties->all())->toBe([
            'requested_amount' => '150000001',
            'currency' => 'USDC',
            'academic_term_id' => $this->term->id,
        ])
        ->and($audit->withoutRelations()->toJson())->not->toContain($this->payload['reason']);
});

test('invalid requested amounts are rejected without writes', function (mixed $amount): void {
    $before = $this->account->getAttributes();
    $this->actingAs($this->student->user)->postJson(route('assistance.store'), [
        ...$this->payload, 'requested_amount' => $amount,
    ])->assertUnprocessable()->assertJsonValidationErrors('requested_amount');

    expect(AssistanceRequest::count())->toBe(0)
        ->and(Activity::where('event', 'submitted')->count())->toBe(0)
        ->and($this->account->fresh()->getAttributes())->toEqual($before);
})->with([
    'exponent' => '1.5e2',
    'negative' => '-1',
    'zero' => '0',
    'decimal zero' => '0.000000',
    'over maximum' => '1000000.000001',
    'seven decimals' => '150.0000001',
    'not numeric' => 'not-money',
    'integer instead of string' => 150,
    'float instead of string' => 150.000001,
    'boolean' => true,
    'array' => [['150']],
    'null' => null,
]);

test('valid amount boundaries are converted exactly', function (string $amount, int $units): void {
    $this->actingAs($this->student->user)->post(route('assistance.store'), [
        ...$this->payload, 'requested_amount' => $amount,
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(AssistanceRequest::sole()->requested_amount)->toBe($units);
})->with([
    ['0.000001', 1],
    ['150', 150000000],
    ['150.1', 150100000],
    ['1000000.000000', 1000000000000],
]);

test('server controlled fields are prohibited', function (string $field, mixed $value): void {
    $this->actingAs($this->student->user)->postJson(route('assistance.store'), [
        ...$this->payload, $field => $value,
    ])->assertUnprocessable()->assertJsonValidationErrors($field);

    expect(AssistanceRequest::count())->toBe(0)
        ->and(Activity::where('event', 'submitted')->count())->toBe(0);
})->with([
    ['student_id', 999],
    ['academic_term_id', 999],
    ['status', 'approved'],
    ['approved_amount', '150'],
    ['ai_decision', 'approved'],
]);

test('identical submission key retries create one request and one audit only', function (): void {
    $before = $this->account->getAttributes();
    $this->actingAs($this->student->user)->post(route('assistance.store'), $this->payload)
        ->assertSessionHasNoErrors()->assertRedirect();
    $request = AssistanceRequest::sole();
    $original = $request->getAttributes();
    $this->travel(1)->minutes();

    $this->post(route('assistance.store'), $this->payload)
        ->assertSessionHasNoErrors()->assertRedirect(route('assistance.show', $request));

    expect(AssistanceRequest::sole()->getAttributes())->toEqual($original)
        ->and(Activity::where('log_name', 'education')->where('event', 'submitted')->count())->toBe(1)
        ->and($this->account->fresh()->getAttributes())->toEqual($before);
});

test('conflicting submission key reuse is rejected without changing the original', function (string $field, string $value): void {
    $this->actingAs($this->student->user)->post(route('assistance.store'), $this->payload)
        ->assertSessionHasNoErrors()->assertRedirect();
    $original = AssistanceRequest::sole()->getAttributes();

    $this->postJson(route('assistance.store'), [...$this->payload, $field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors('submission_key');

    expect(AssistanceRequest::sole()->getAttributes())->toEqual($original)
        ->and(Activity::where('log_name', 'education')->where('event', 'submitted')->count())->toBe(1);
})->with([
    ['requested_amount', '150.000002'],
    ['reason', 'A different emergency with different private details.'],
]);

test('submission requires exactly one current term tuition account', function (string $scenario): void {
    match ($scenario) {
        'missing' => $this->account->delete(),
        'expired' => $this->term->update(['ends_on' => today()->subDay()]),
        'future' => $this->term->update(['starts_on' => today()->addDay()]),
        'overlapping' => TuitionAccount::factory()->for($this->student)->create(),
    };
    $accounts = TuitionAccount::all()->map->getAttributes()->all();

    $this->actingAs($this->student->user)->get(route('student.dashboard'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('canRequest', false)->where('tuitionAccount', null));
    $this->get(route('assistance.create'))->assertRedirect(route('student.dashboard'));
    $this->postJson(route('assistance.store'), $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors('requested_amount');

    expect(AssistanceRequest::count())->toBe(0)
        ->and(Activity::where('event', 'submitted')->count())->toBe(0)
        ->and(TuitionAccount::all()->map->getAttributes()->all())->toEqual($accounts);
})->with(['missing', 'expired', 'future', 'overlapping']);

test('user audit logging allowlists profile fields and never records password or two factor secrets', function (): void {
    $user = User::factory()->create([
        'password' => 'initial-private-password',
        'remember_token' => 'initial-private-token',
        'two_factor_secret' => encrypt('initial-totp-secret'),
        'two_factor_recovery_codes' => encrypt('["initial-recovery-code"]'),
        'app_authentication_secret' => 'initial-app-secret',
        'app_authentication_recovery_codes' => ['initial-app-recovery'],
    ]);
    $user->forceFill([
        'name' => 'Updated audit profile',
        'password' => 'updated-private-password',
        'remember_token' => 'updated-private-token',
        'two_factor_secret' => encrypt('updated-totp-secret'),
        'two_factor_recovery_codes' => encrypt('["updated-recovery-code"]'),
        'app_authentication_secret' => 'updated-app-secret',
        'app_authentication_recovery_codes' => ['updated-app-recovery'],
    ])->save();

    $logs = Activity::forSubject($user)->get();
    expect($logs)->toHaveCount(2)
        ->and($logs->firstWhere('event', 'updated')->properties->get('attributes'))->toBe(['name' => 'Updated audit profile']);

    foreach ($logs as $log) {
        foreach (['attributes', 'old'] as $section) {
            expect(array_diff(array_keys($log->properties->get($section, [])), [
                'name', 'email', 'email_verified_at', 'profile_photo_path',
            ]))->toBeEmpty();
        }
    }

    $user->forceFill([
        'password' => 'secret-only-password-change',
        'two_factor_secret' => encrypt('secret-only-totp-change'),
        'app_authentication_secret' => 'secret-only-app-change',
    ])->save();

    expect(Activity::forSubject($user)->count())->toBe(2);
});
