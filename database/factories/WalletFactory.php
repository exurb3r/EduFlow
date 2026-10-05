<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'provider' => 'circle',
            'network' => 'arc',
            'address' => '0x'.bin2hex(random_bytes(20)),
            'balance' => 25420.00,
            'status' => 'active',
        ];
    }
}
