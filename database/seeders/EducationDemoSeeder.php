<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Enums\AssistanceStatus;
use App\Models\AcademicTerm;
use App\Models\AssistanceRequest;
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
                // A real, well-formed destination so assistance actually settles
                // on ARC-TESTNET. Any 0x address with 40 hex digits is accepted;
                // EIP-55 checksum casing is not required.
                'payout_address' => '0x4b1c0d2e3f4a5b6c7d8e9f0a1b2c3d4e5f607182',
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

            // A pending request at 15 USDC against a 10 USDC auto-limit, so the
            // demo has a bounded split to narrate: 10 approved automatically,
            // 5 escalated for advisor review. Amounts are 6-decimal base units.
            AssistanceRequest::firstOrCreate(
                ['submission_key' => 'eduflow-demo-emergency-aid'],
                [
                    'ticket_number' => 'AST-DEMO01',
                    'student_id' => $student->id,
                    'academic_term_id' => $term->id,
                    'user_id' => $juan->id,
                    'category' => AssistanceCategory::ACADEMIC,
                    'priority' => AssistancePriority::HIGH,
                    'status' => AssistanceStatus::SUBMITTED,
                    'subject' => 'Emergency tuition assistance for laboratory materials',
                    'description' => 'Lab coat and materials for the capstone are due before the term deadline.',
                    'type' => 'emergency',
                    'requested_amount' => 15_000000,
                    'reason' => 'Course materials are a prerequisite for completing the capstone submission.',
                    'submitted_at' => now(),
                ]
            );
        });
    }
}
