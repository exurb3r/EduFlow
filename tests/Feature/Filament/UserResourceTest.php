<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Users\Widgets\UserProfileSummaryWidget;
use App\Filament\Resources\Users\Widgets\UsersStatsWidget;
use App\Models\Student;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'finance_officer', 'student', 'user'] as $role) {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
    }

    foreach (['ViewAny:User', 'View:User', 'Update:User', 'Create:User', 'Delete:User'] as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
    }

    Role::findByName('super_admin', 'web')->syncPermissions(Permission::all());
});

test('user resource is registered in admin panel', function (): void {
    expect(UserResource::getUrl('index', panel: 'admin'))->toContain('users');
});

test('staff can open user list with directory tabs and stats widget', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $studentUser = User::factory()->create(['name' => 'Student Persona']);
    Student::factory()->for($studentUser)->create(['student_number' => 'STU-TEST-001']);

    $plainUser = User::factory()->create(['name' => 'Plain User']);

    Livewire::test(ListUsers::class)
        ->assertSuccessful()
        ->assertSee('Student Persona')
        ->assertSee('Plain User')
        ->set('activeTab', 'students')
        ->assertCanSeeTableRecords([$studentUser])
        ->assertCanNotSeeTableRecords([$plainUser]);

    Livewire::test(UsersStatsWidget::class)
        ->assertSuccessful()
        ->assertSee('Total Users')
        ->assertSee('Enrolled Students')
        ->assertSee('MFA Secured');
});

test('view user page displays student profile when user is a student', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $user = User::factory()->create();
    $student = Student::factory()->for($user)->create([
        'student_number' => 'STU-VIEW-999',
        'program' => 'BS Information Technology',
        'year_level' => 3,
    ]);

    Livewire::test(ViewUser::class, ['record' => $user->getKey()])
        ->assertSuccessful()
        ->assertSee('STU-VIEW-999')
        ->assertSee('BS Information Technology')
        ->assertSee('Year 3');

    Livewire::test(UserProfileSummaryWidget::class, ['record' => $user])
        ->assertSuccessful()
        ->assertSee($user->name)
        ->assertSee('STU-VIEW-999');
});

test('view user page displays empty state when user is not a student', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $user = User::factory()->create();

    Livewire::test(ViewUser::class, ['record' => $user->getKey()])
        ->assertSuccessful()
        ->assertSee('No student profile linked')
        ->assertSee('Link a Student Record');
});

test('staff can link a student profile to a user from the view page modal action', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $user = User::factory()->create(['name' => 'Onboarding Candidate']);

    expect($user->student()->exists())->toBeFalse();

    Livewire::test(ViewUser::class, ['record' => $user->getKey()])
        ->mountAction('linkStudent')
        ->set('mountedActions.0.data.student_number', 'STU-MODAL-777')
        ->set('mountedActions.0.data.program', 'BS Computer Science')
        ->set('mountedActions.0.data.year_level', 2)
        ->set('mountedActions.0.data.enrollment_status', 'enrolled')
        ->set('mountedActions.0.data.academic_status', 'qualified')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($user->fresh()->student()->exists())->toBeTrue();
    $student = $user->fresh()->student;
    expect($student->student_number)->toBe('STU-MODAL-777')
        ->and($student->program)->toBe('BS Computer Science')
        ->and($student->year_level)->toBe(2);
});

test('staff can toggle email verification on view user page', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $user = User::factory()->create(['email_verified_at' => null]);
    expect($user->email_verified_at)->toBeNull();

    Livewire::test(ViewUser::class, ['record' => $user->getKey()])
        ->callAction('toggleEmailVerification')
        ->assertHasNoActionErrors();

    expect($user->fresh()->email_verified_at)->not->toBeNull();

    Livewire::test(ViewUser::class, ['record' => $user->getKey()])
        ->callAction('toggleEmailVerification')
        ->assertHasNoActionErrors();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('edit user page renders successfully', function (): void {
    $this->actingAs(User::factory()->create()->assignRole('super_admin'));
    filament()->setCurrentPanel('admin');

    $user = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $user->getKey()])
        ->assertSuccessful()
        ->assertSee($user->email);
});
