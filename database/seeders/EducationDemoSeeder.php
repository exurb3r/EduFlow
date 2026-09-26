<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AcademicTerm;
use App\Models\Role;
use App\Models\Student;
use App\Models\TuitionAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class EducationDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Education demo data may only be seeded in local or testing environments.');
        }

        DB::transaction(function (): void {
            $studentRole = Role::firstOrCreate(['name' => 'student', 'guard_name' => 'web']);
            $financeRole = Role::firstOrCreate(['name' => 'finance_officer', 'guard_name' => 'web']);

            // User currently logs all attributes; keep demo password hashes out of activity logs.
            [$juan, $finance] = User::withoutEvents(fn (): array => [
                User::firstOrCreate(['email' => 'juan@eduflow.test'], [
                    'name' => 'Juan',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]),
                User::firstOrCreate(['email' => 'finance@eduflow.test'], [
                    'name' => 'Finance Officer',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]),
            ]);

            $juan->assignRole($studentRole);
            $finance->assignRole($financeRole);

            $student = Student::firstOrCreate(['user_id' => $juan->id], [
                'student_number' => 'DEMO-JUAN',
                'program' => 'BS Information Technology',
                'year_level' => 2,
                'enrollment_status' => 'enrolled',
                'academic_status' => 'qualified',
                'attendance_rate' => 95,
            ]);

            $term = AcademicTerm::firstOrCreate(['name' => 'Demo Academic Term '.today()->year], [
                'starts_on' => today()->startOfYear(),
                'ends_on' => today()->endOfYear(),
            ]);

            TuitionAccount::firstOrCreate([
                'student_id' => $student->id,
                'academic_term_id' => $term->id,
            ], [
                'total_amount' => 300000000,
                'paid_amount' => 0,
            ]);
        });
    }
}
