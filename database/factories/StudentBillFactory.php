<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StudentBillCategory;
use App\Models\AcademicTerm;
use App\Models\Student;
use App\Models\StudentBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StudentBill> */
class StudentBillFactory extends Factory
{
    protected $model = StudentBill::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'academic_term_id' => AcademicTerm::factory(),
            'category' => StudentBillCategory::MISCELLANEOUS,
            'description' => 'Miscellaneous fee',
            // 150.000000 USDC as exact 6-decimal base units. An integer, never
            // a float: a decimal quote like 150.01 does not survive a float
            // round-trip intact.
            'amount_base_units' => 150_000_000,
            'paid_base_units' => 0,
            'due_date' => today()->addDays(30),
            'status' => StudentBill::STATUS_OPEN,
        ];
    }
}
