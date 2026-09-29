<?php

declare(strict_types=1);

use App\Enums\AssistanceStatus;
use App\Models\AcademicTerm;
use App\Models\AssistanceRequest;
use App\Models\Role;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\EducationDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

test('education factories persist relationships and typed values', function (): void {
    $student = Student::factory()->create(['attendance_rate' => '97.25', 'year_level' => '3']);
    $term = AcademicTerm::factory()->create();
    $account = TuitionAccount::factory()->for($student)->for($term)->create([
        'total_amount' => '300000000', 'paid_amount' => '125000000',
    ]);
    $request = AssistanceRequest::factory()->for($student)->for($term)->create([
        'requested_amount' => 5000000000,
    ]);

    expect($student->user)->toBeInstanceOf(User::class)
        ->and($student->year_level)->toBe(3)
        ->and($student->attendance_rate)->toBe('97.25')
        ->and($student->tuitionAccounts->sole()->is($account))->toBeTrue()
        ->and($student->assistanceRequests->sole()->is($request))->toBeTrue()
        ->and($account->student->is($student))->toBeTrue()
        ->and($account->academicTerm->is($term))->toBeTrue()
        ->and($account->total_amount)->toBe(300000000)
        ->and($account->paid_amount)->toBe(125000000)
        ->and($account->remainingAmount())->toBe(175000000)
        ->and($request->student->is($student))->toBeTrue()
        ->and($request->academicTerm->is($term))->toBeTrue()
        ->and($request->fresh()->requested_amount)->toBe(5000000000)
        ->and($request->submitted_at)->toBeInstanceOf(CarbonInterface::class)
        ->and(Str::isUuid($request->submission_key))->toBeTrue()
        ->and($term->starts_on)->toBeInstanceOf(CarbonInterface::class)
        ->and($term->ends_on)->toBeInstanceOf(CarbonInterface::class);
});

test('remaining amount is derived without clamping', function (int $paid, int $remaining): void {
    $account = TuitionAccount::factory()->make(['total_amount' => 300000000, 'paid_amount' => $paid]);

    expect($account->remainingAmount())->toBe($remaining);
})->with([[0, 300000000], [300000000, 0], [300000001, -1]]);

test('academic term activity includes both boundary dates', function (string $date, bool $active): void {
    $this->travelTo(Carbon::parse($date));
    $term = AcademicTerm::factory()->make(['starts_on' => '2026-06-01', 'ends_on' => '2026-09-30']);

    expect($term->isActive())->toBe($active);
})->with([
    ['2026-05-31 23:59:59', false],
    ['2026-06-01 00:00:00', true],
    ['2026-09-30 23:59:59', true],
    ['2026-10-01 00:00:00', false],
]);

test('invalid or incomplete term dates are not active', function (): void {
    $this->travelTo(Carbon::parse('2026-06-15'));

    expect((new AcademicTerm)->isActive())->toBeFalse()
        ->and((new AcademicTerm(['starts_on' => '2026-07-01', 'ends_on' => '2026-06-01']))->isActive())->toBeFalse();
});

test('database rejects invalid tuition amounts on inserts and direct updates', function (int $total, int $paid, string $operation): void {
    $account = TuitionAccount::factory()->create();
    $attributes = ['total_amount' => $total, 'paid_amount' => $paid];

    expect(fn () => $operation === 'insert'
        ? DB::table($account->getTable())->insert([
            ...$attributes,
            'student_id' => Student::factory()->create()->id,
            'academic_term_id' => $account->academic_term_id,
        ])
        : DB::table($account->getTable())->where('id', $account->id)->update($attributes)
    )->toThrow(QueryException::class);
})->with([[-1, 0], [100, -1], [100, 101]])->with(['insert', 'update']);

test('database rejects negative requested amounts on inserts and direct updates', function (string $operation): void {
    $request = AssistanceRequest::factory()->create();

    expect(fn () => $operation === 'insert'
        ? DB::table($request->getTable())->insert([
            'student_id' => $request->student_id,
            'academic_term_id' => $request->academic_term_id,
            'requested_amount' => -1,
            'reason' => 'Emergency costs',
            'submission_key' => (string) Str::uuid(),
            'submitted_at' => now(),
        ])
        : DB::table($request->getTable())->where('id', $request->id)->update(['requested_amount' => -1])
    )->toThrow(QueryException::class);
})->with(['insert', 'update']);

test('database accepts zero and fully paid amount boundaries', function (): void {
    $account = TuitionAccount::factory()->create(['total_amount' => 0, 'paid_amount' => 0]);
    $account->update(['total_amount' => 300000000, 'paid_amount' => 300000000]);
    $request = AssistanceRequest::factory()->create(['requested_amount' => 0]);

    expect($account->fresh()->remainingAmount())->toBe(0)
        ->and($request->fresh()->requested_amount)->toBe(0);
});

test('education defaults apply to models and raw database inserts', function (): void {
    expect((new Student)->enrollment_status)->toBe('enrolled')
        ->and((new Student)->academic_status)->toBe('qualified')
        ->and((new AssistanceRequest)->type)->toBe('emergency')
        ->and((new AssistanceRequest)->status instanceof AssistanceStatus ? (new AssistanceRequest)->status->value : (new AssistanceRequest)->status)->toBe('submitted');

    $studentId = DB::table('students')->insertGetId([
        'user_id' => User::factory()->create()->id,
        'student_number' => 'DEFAULT-TEST', 'program' => 'BSIT', 'year_level' => 1,
    ]);
    $student = Student::findOrFail($studentId);
    $requestId = DB::table('assistance_requests')->insertGetId([
        'student_id' => $studentId,
        'academic_term_id' => AcademicTerm::factory()->create()->id,
        'requested_amount' => 123, 'reason' => 'Emergency costs',
        'submission_key' => (string) Str::uuid(), 'submitted_at' => now(),
    ]);
    $request = AssistanceRequest::findOrFail($requestId);

    expect($student->enrollment_status)->toBe('enrolled')
        ->and($student->academic_status)->toBe('qualified')
        ->and($student->attendance_rate)->toBeNull()
        ->and($request->type)->toBe('emergency')
        ->and($request->status instanceof AssistanceStatus ? $request->status->value : $request->status)->toBe('submitted');
});

test('students enforce unique identity fields', function (string $field): void {
    $student = Student::factory()->create();

    expect(fn () => Student::factory()->create([$field => $student->{$field}]))
        ->toThrow(QueryException::class);
})->with(['user_id', 'student_number']);

test('academic term names are unique', function (): void {
    $term = AcademicTerm::factory()->create();

    expect(fn () => AcademicTerm::factory()->create(['name' => $term->name]))->toThrow(QueryException::class);
});

test('tuition accounts are unique per student and term', function (): void {
    $account = TuitionAccount::factory()->create();
    TuitionAccount::factory()->for($account->student)->create();
    TuitionAccount::factory()->for($account->academicTerm)->create();

    expect(fn () => TuitionAccount::factory()->for($account->student)->for($account->academicTerm)->create())
        ->toThrow(QueryException::class);
});

test('submission keys are unique per student across terms', function (): void {
    $request = AssistanceRequest::factory()->create();
    AssistanceRequest::factory()->create(['submission_key' => $request->submission_key]);
    AssistanceRequest::factory()->for($request->student)->create();

    expect(fn () => AssistanceRequest::factory()->for($request->student)->create([
        'submission_key' => $request->submission_key,
    ]))->toThrow(QueryException::class);
});

test('education foreign keys reject missing parents', function (string $model, string $field): void {
    expect(fn () => $model::factory()->create([$field => 999999]))->toThrow(QueryException::class);
})->with([
    [Student::class, 'user_id'],
    [TuitionAccount::class, 'student_id'],
    [TuitionAccount::class, 'academic_term_id'],
    [AssistanceRequest::class, 'student_id'],
    [AssistanceRequest::class, 'academic_term_id'],
]);

test('education demo seeder provisions demo data only in allowed environments', function (string $environment): void {
    app()->instance('env', $environment);
    $this->seed(EducationDemoSeeder::class);

    $juan = User::where('email', 'juan@eduflow.test')->sole();
    $finance = User::where('email', 'finance@eduflow.test')->sole();
    $student = Student::where('user_id', $juan->id)->sole();
    $account = $student->tuitionAccounts->sole();

    expect($juan->hasRole('student'))->toBeTrue()
        ->and($finance->hasRole('finance_officer'))->toBeTrue()
        ->and(Hash::check('password', $juan->password))->toBeTrue()
        ->and(Hash::check('password', $finance->password))->toBeTrue()
        ->and($account->total_amount)->toBe(300000000)
        ->and($account->paid_amount)->toBe(0)
        ->and($account->academicTerm->isActive())->toBeTrue()
        // One pending request, above the 10 USDC auto-limit so the demo has a
        // bounded split to narrate.
        ->and(AssistanceRequest::count())->toBe(1)
        ->and(AssistanceRequest::sole()->requested_amount)->toBe(15_000000);
})->with(['local', 'testing']);

test('education demo seeder preserves existing data and passwords on reruns', function (): void {
    $this->travelTo(Carbon::parse('2026-06-15'));
    $this->seed(EducationDemoSeeder::class);
    $juan = User::where('email', 'juan@eduflow.test')->sole();
    $finance = User::where('email', 'finance@eduflow.test')->sole();
    $juan->update(['name' => 'Updated Juan', 'password' => 'changed-password']);
    $finance->update(['name' => 'Updated Finance', 'password' => 'changed-finance-password']);
    $student = Student::sole();
    $student->update(['program' => 'Updated Program', 'attendance_rate' => 88]);
    $account = TuitionAccount::sole();
    $account->update(['total_amount' => 400000000, 'paid_amount' => 100000000]);
    $term = AcademicTerm::sole();
    $term->update(['starts_on' => '2020-01-01', 'ends_on' => '2020-12-31']);
    $original = [$juan->getAttributes(), $finance->getAttributes(), $student->getAttributes(), $account->getAttributes(), $term->getAttributes()];

    $this->travel(1)->days();
    $this->seed(EducationDemoSeeder::class);

    expect([$juan->fresh()->getAttributes(), $finance->fresh()->getAttributes(), $student->fresh()->getAttributes(), $account->fresh()->getAttributes(), $term->fresh()->getAttributes()])->toBe($original)
        ->and(User::count())->toBe(2)
        ->and(Student::count())->toBe(1)
        ->and(AcademicTerm::count())->toBe(1)
        ->and(TuitionAccount::count())->toBe(1)
        ->and(Role::whereIn('name', ['student', 'finance_officer'])->count())->toBe(2);
});

test('education demo seeder refuses non demo environments before writing', function (string $environment): void {
    app()->instance('env', $environment);

    expect(fn () => (new EducationDemoSeeder)->run())->toThrow(RuntimeException::class)
        ->and(User::count())->toBe(0)
        ->and(Role::count())->toBe(0)
        ->and(Student::count())->toBe(0)
        ->and(AcademicTerm::count())->toBe(0)
        ->and(TuitionAccount::count())->toBe(0);
})->with(['production', 'staging']);
