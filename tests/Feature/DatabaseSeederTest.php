<?php

declare(strict_types=1);

use App\Enums\RoleEnums;
use App\Models\AcademicTerm;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('database seeder creates the default roles', function (): void {
    Role::query()->delete();

    $this->seed(DatabaseSeeder::class);

    $roles = Role::query()->pluck('name')->all();

    foreach (RoleEnums::values() as $role) {
        expect($roles)->toContain($role);
    }

    expect(Role::query()->where('name', RoleEnums::USER->value)->where('guard_name', 'web')->exists())->toBeTrue()
        ->and(User::where('email', 'juan@eduflow.test')->exists())->toBeTrue()
        ->and(Student::where('student_number', 'DEMO-JUAN')->exists())->toBeTrue()
        ->and(AcademicTerm::exists())->toBeTrue()
        ->and(TuitionAccount::where('total_amount', 300000000)->where('paid_amount', 0)->exists())->toBeTrue();
});

test('database seeding outside demo environments creates roles but no users or demo records', function (string $environment): void {
    app()->instance('env', $environment);

    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();

    expect(Role::count())->toBe(count(RoleEnums::cases()))
        ->and(Permission::count())->toBeGreaterThan(0)
        ->and(User::count())->toBe(0)
        ->and(Organization::count())->toBe(0)
        ->and(Student::count())->toBe(0)
        ->and(AcademicTerm::count())->toBe(0)
        ->and(TuitionAccount::count())->toBe(0)
        ->and(AssistanceRequest::count())->toBe(0);
})->with(['production', 'staging']);

test('direct role seeding cannot elevate a default-email account outside demo environments', function (string $environment): void {
    $existing = User::factory()->create(['email' => 'admin@admin.com']);
    $password = $existing->getRawOriginal('password');
    app()->instance('env', $environment);

    $this->artisan('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true])->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and($existing->fresh()->getRawOriginal('password'))->toBe($password)
        ->and($existing->fresh()->hasRole(RoleEnums::SUPER_ADMIN))->toBeFalse();
})->with(['production', 'staging']);

test('role seeding preserves local demo accounts and is idempotent', function (string $environment): void {
    app()->instance('env', $environment);

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    $admin = User::where('email', 'admin@admin.com')->sole();

    expect(User::count())->toBe(2)
        ->and($admin->hasRole(RoleEnums::SUPER_ADMIN))->toBeTrue()
        ->and(Hash::check('password', $admin->password))->toBeTrue();
})->with(['local', 'testing']);
