<?php

declare(strict_types=1);

use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Students\Pages\EditStudent;
use App\Filament\Resources\Students\Pages\ViewStudent;
use App\Filament\Resources\Students\RelationManagers\AssistanceRequestsRelationManager;
use App\Filament\Resources\Students\RelationManagers\StudentBillsRelationManager;
use App\Filament\Resources\Students\RelationManagers\TuitionAccountsRelationManager;
use App\Filament\Resources\Students\StudentResource;
use App\Filament\Resources\Students\Widgets\StudentProfileSummaryWidget;
use App\Filament\Resources\Students\Widgets\StudentsStatsWidget;
use App\Filament\Resources\TuitionAccounts\Pages\CreateTuitionAccount;
use App\Models\AcademicTerm;
use App\Models\AssistanceRequest;
use App\Models\Student;
use App\Models\StudentBill;
use App\Models\TuitionAccount;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'finance_officer', 'student', 'user'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }
});

test('students resource is registered in the admin and finance panels', function (): void {
    expect(StudentResource::getUrl('index', panel: 'admin'))->toContain('students')
        ->and(StudentResource::getUrl('index', panel: 'finance'))->toContain('students');
});

test('staff can open the students list in both panels', function (string $role, string $panel): void {
    $this->actingAs(User::factory()->create()->assignRole($role));

    $this->get(StudentResource::getUrl('index', panel: $panel))->assertSuccessful();
})->with([
    ['super_admin', 'admin'],
    ['super_admin', 'finance'],
    ['admin', 'finance'],
    ['finance_officer', 'finance'],
]);

test('nonstaff cannot open the students list', function (string $role): void {
    $this->actingAs(User::factory()->create()->assignRole($role));

    $this->get(StudentResource::getUrl('index', panel: 'admin'))->assertForbidden();
    $this->get(StudentResource::getUrl('index', panel: 'finance'))->assertForbidden();
})->with(['student', 'user']);

test('staff can link a student record to a user account', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('admin'));
    filament()->setCurrentPanel('finance');

    $user = User::factory()->create();

    Livewire::test(CreateStudent::class)
        ->fillForm([
            'user_id' => $user->getKey(),
            'student_number' => 'STU-000001',
            'program' => 'BS Information Technology',
            'year_level' => 2,
            'enrollment_status' => 'enrolled',
            'academic_status' => 'qualified',
            'attendance_rate' => '95.00',
            'payout_address' => '0x'.str_repeat('b', 40),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $student = Student::query()->firstOrFail();
    expect($student->user_id)->toBe($user->getKey())
        ->and($student->student_number)->toBe('STU-000001')
        ->and($user->fresh()->student()->exists())->toBeTrue();
});

test('a user account can only back one student record', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $existing = Student::factory()->create();

    Livewire::test(CreateStudent::class)
        ->fillForm([
            'user_id' => $existing->user_id,
            'student_number' => 'STU-999999',
            'program' => 'BS Information Technology',
            'year_level' => 1,
            'enrollment_status' => 'enrolled',
            'academic_status' => 'qualified',
        ])
        ->call('create')
        ->assertHasFormErrors(['user_id']);

    expect(Student::query()->count())->toBe(1);
});

test('malformed payout addresses are rejected', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    filament()->setCurrentPanel('finance');

    $user = User::factory()->create();

    Livewire::test(CreateStudent::class)
        ->fillForm([
            'user_id' => $user->getKey(),
            'student_number' => 'STU-000002',
            'program' => 'BS Information Technology',
            'year_level' => 1,
            'enrollment_status' => 'enrolled',
            'academic_status' => 'qualified',
            'payout_address' => '0xnot-a-real-address',
        ])
        ->call('create')
        ->assertHasFormErrors(['payout_address']);

    expect(Student::query()->count())->toBe(0);
});

test('staff can update a student record from the edit page', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $student = Student::factory()->create(['academic_status' => 'qualified']);

    Livewire::test(EditStudent::class, ['record' => $student->getKey()])
        ->fillForm(['academic_status' => 'probation'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($student->fresh()->academic_status)->toBe('probation');
});

test('a linked record, not the role, is what puts a balance on the dashboard', function (): void {
    $staff = User::factory()->create()->assignRole('super_admin');
    $user = User::factory()->create()->assignRole('user');
    $term = AcademicTerm::factory()->create();

    // Role alone is not enough: no Student row, no balance.
    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('finance.tuitionAccount', null));

    // Staff link the record and set the balance through the panel.
    $this->actingAs($staff);
    filament()->setCurrentPanel('admin');

    Livewire::test(CreateStudent::class)
        ->fillForm([
            'user_id' => $user->getKey(),
            'student_number' => 'STU-777001',
            'program' => 'BS Information Technology',
            'year_level' => 2,
            'enrollment_status' => 'enrolled',
            'academic_status' => 'qualified',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $student = Student::query()->where('user_id', $user->getKey())->firstOrFail();

    Livewire::test(CreateTuitionAccount::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'academic_term_id' => $term->getKey(),
            'total_amount' => '300.00',
            'paid_amount' => '25.00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    // Same user, same role — the balance now shows.
    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('finance.tuitionAccount.term', $term->name)
            ->where('finance.tuitionAccount.remaining_amount', '275000000'));

    expect(TuitionAccount::query()->count())->toBe(1);
});

test('a student with financial history on file cannot be deleted from the edit page', function (): void {
    $student = Student::factory()->create();
    $term = AcademicTerm::factory()->create();
    TuitionAccount::factory()->for($student)->for($term)->create([
        'total_amount' => 300000000,
        'paid_amount' => 0,
    ]);

    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('finance');

    Livewire::test(EditStudent::class, ['record' => $student->getKey()])
        ->callAction(DeleteAction::class);

    // The guard halts before any query runs, so the row survives. The panel
    // explains why instead of raising a foreign-key error.
    expect(Student::query()->find($student->getKey()))->not->toBeNull();
});

test('a student with nothing on file can still be deleted', function (): void {
    $student = Student::factory()->withoutPayoutAddress()->create();

    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('finance');

    Livewire::test(EditStudent::class, ['record' => $student->getKey()])
        ->callAction(DeleteAction::class)
        ->assertHasNoActionErrors();

    expect(Student::query()->find($student->getKey()))->toBeNull();
});

test('a student is only deletable once their financial history is gone', function (): void {
    $student = Student::factory()->create();
    $term = AcademicTerm::factory()->create();

    expect($student->deletionBlockedReason())->toBeNull();

    $account = TuitionAccount::factory()->for($student)->for($term)->create([
        'total_amount' => 300000000,
        'paid_amount' => 0,
    ]);

    expect($student->fresh()->deletionBlockedReason())
        ->toContain('1 tuition account')
        ->toContain('financial history');

    // Clearing the history is what unblocks the delete; the student row itself
    // was never the thing protecting the money.
    $account->delete();

    expect($student->fresh()->deletionBlockedReason())->toBeNull();
});

test('staff can open the view student page and see academic profile and wallet', function (string $role, string $panel): void {
    $this->actingAs(User::factory()->create()->assignRole($role));
    filament()->setCurrentPanel($panel);

    $student = Student::factory()->create([
        'program' => 'BS Information Technology',
        'year_level' => 3,
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => '96.50',
    ]);

    $this->get(StudentResource::getUrl('view', ['record' => $student->getKey()], panel: $panel))
        ->assertSuccessful();

    Livewire::test(ViewStudent::class, ['record' => $student->getKey()])
        ->assertSuccessful()
        ->assertSee($student->user->name)
        ->assertSee($student->student_number)
        ->assertSee('BS Information Technology')
        ->assertSee('Year 3')
        ->assertSee('96.5%');
})->with([
    ['super_admin', 'admin'],
    ['super_admin', 'finance'],
    ['admin', 'finance'],
    ['finance_officer', 'finance'],
]);

test('student profile summary widget renders student metrics', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    filament()->setCurrentPanel('finance');

    $student = Student::factory()->create();

    Livewire::test(StudentProfileSummaryWidget::class, ['record' => $student])
        ->assertSuccessful()
        ->assertSee($student->user->name)
        ->assertSee($student->student_number)
        ->assertSee('Academic Curriculum')
        ->assertSee('Outstanding Tuition');
});

test('students stats widget renders directory counters', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    filament()->setCurrentPanel('finance');

    Student::factory()->count(3)->create();

    Livewire::test(StudentsStatsWidget::class)
        ->assertSuccessful()
        ->assertSee('Total Students')
        ->assertSee('Active Enrolled')
        ->assertSee('Qualified Standing');
});

test('staff can create a tuition account directly from the view student page', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    filament()->setCurrentPanel('finance');

    $student = Student::factory()->create();
    $term = AcademicTerm::factory()->create();

    Livewire::test(ViewStudent::class, ['record' => $student->getKey()])
        ->mountAction('createTuitionAccount')
        ->set('mountedActions.0.data.academic_term_id', $term->getKey())
        ->set('mountedActions.0.data.total_amount', '450.00')
        ->set('mountedActions.0.data.paid_amount', '50.00')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(TuitionAccount::query()->where('student_id', $student->id)->count())->toBe(1);

    $account = TuitionAccount::query()->where('student_id', $student->id)->firstOrFail();
    expect($account->total_amount)->toBe(450000000)
        ->and($account->paid_amount)->toBe(50000000)
        ->and($account->remainingAmount())->toBe(400000000);
});

test('student relation managers render related accounts, requests, and bills', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('finance_officer'));
    filament()->setCurrentPanel('finance');

    $student = Student::factory()->create();
    $term = AcademicTerm::factory()->create();

    $account = TuitionAccount::factory()->for($student)->for($term)->create([
        'total_amount' => 300000000,
        'paid_amount' => 100000000,
    ]);

    $request = AssistanceRequest::factory()->for($student->user)->create([
        'student_id' => $student->id,
        'subject' => 'Emergency book grant',
    ]);

    $bill = StudentBill::factory()->for($student)->create([
        'description' => 'Laboratory Equipment Fee',
        'amount_base_units' => 50000000,
        'paid_base_units' => 0,
    ]);

    Livewire::test(TuitionAccountsRelationManager::class, [
        'ownerRecord' => $student,
        'pageClass' => ViewStudent::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$account])
        ->assertSee('300.00 USDC');

    Livewire::test(AssistanceRequestsRelationManager::class, [
        'ownerRecord' => $student,
        'pageClass' => ViewStudent::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$request])
        ->assertSee('Emergency book grant');

    Livewire::test(StudentBillsRelationManager::class, [
        'ownerRecord' => $student,
        'pageClass' => ViewStudent::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$bill])
        ->assertSee('Laboratory Equipment Fee');
});
