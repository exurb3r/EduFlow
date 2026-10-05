<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicTerm;
use App\Models\Student;
use App\Models\TuitionAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TuitionAccount> */
class TuitionAccountFactory extends Factory
{
    protected $model = TuitionAccount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'academic_term_id' => AcademicTerm::factory(),
            'total_amount' => 300000000,
            'paid_amount' => 0,
        ];
    }
}
