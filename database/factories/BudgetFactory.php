<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Scholarships & Student Assistance',
            'category' => 'scholarships',
            'allocated_amount' => 2000.00,
            'spent_amount' => 0.00,
            'remaining_amount' => 2000.00,
            'status' => 'active',
        ];
    }
}
