<?php

declare(strict_types=1);

use App\Enums\RoleEnums;
use App\Models\AcademicTerm;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
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
