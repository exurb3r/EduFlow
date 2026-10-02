<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Organization> */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' School',
            'type' => 'school',
            'currency' => 'USDC',
            'minimum_reserve' => 0,
            'max_auto_payment' => 0,
            'max_daily_disbursement' => 0,
            'human_approval_threshold' => 0,
        ];
    }
}
