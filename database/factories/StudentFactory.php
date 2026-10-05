<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Student> */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'student_number' => fake()->unique()->numerify('STU-########'),
            'program' => 'BS Information Technology',
            'year_level' => fake()->numberBetween(1, 4),
            'enrollment_status' => 'enrolled',
            'academic_status' => 'qualified',
            'attendance_rate' => null,
            // A well-formed destination, so assistance can actually settle.
            'payout_address' => '0x'.fake()->regexify('[0-9a-f]{40}'),
        ];
    }

    /**
     * A student with no payout address, who therefore cannot be disbursed to.
     */
    public function withoutPayoutAddress(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payout_address' => null,
        ]);
    }

    public function withInvalidPayoutAddress(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payout_address' => '0xnot-a-real-address',
        ]);
    }
}
