<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Northstar Learning Center',
            'type' => 'school',
            'currency' => 'USDC',
            'minimum_reserve' => 10000.00,
            'max_auto_payment' => 1000.00,
            'max_daily_disbursement' => 5000.00,
            'human_approval_threshold' => 1000.00,
        ];
    }
}
