<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use App\Enums\AssistanceStatus;
use App\Models\AcademicTerm;
use App\Models\AssistanceRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AssistanceRequest>
 */
class AssistanceRequestFactory extends Factory
{
    protected $model = AssistanceRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_number' => 'AST-'.strtoupper(Str::random(6)),
            'student_id' => Student::factory(),
            'academic_term_id' => AcademicTerm::factory(),
            'user_id' => null,
            'category' => fake()->randomElement(AssistanceCategory::cases()),
            'priority' => fake()->randomElement(AssistancePriority::cases()),
            'status' => 'submitted',
            'subject' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'admin_notes' => null,
            'assigned_to' => null,
            'resolved_at' => null,
            'type' => 'emergency',
            'requested_amount' => 10000000,
            'reason' => fake()->paragraph(),
            'submission_key' => fake()->uuid(),
            'submitted_at' => now(),
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AssistanceStatus::IN_PROGRESS,
            'assigned_to' => User::factory(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AssistanceStatus::RESOLVED,
            'admin_notes' => fake()->paragraph(),
            'resolved_at' => now(),
        ]);
    }
}
