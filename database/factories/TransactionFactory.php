<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'wallet_id' => Wallet::factory(),
            'type' => 'student_assistance',
            'recipient_address' => '0x'.bin2hex(random_bytes(20)),
            'amount' => 100.00,
            'currency' => 'USDC',
            'status' => 'confirmed',
            'provider_tx_hash' => '0x'.bin2hex(random_bytes(20)),
            'network' => 'arc-testnet',
            'reference_type' => null,
            'reference_id' => null,
            'metadata' => null,
            'executed_at' => now(),
        ];
    }
}
