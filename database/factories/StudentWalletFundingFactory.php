<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StudentWalletFundingStatus;
use App\Models\StudentWallet;
use App\Models\StudentWalletFunding;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StudentWalletFunding> */
class StudentWalletFundingFactory extends Factory
{
    protected $model = StudentWalletFunding::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'student_wallet_id' => StudentWallet::factory(),
            'chain' => 'ARC-TESTNET',
            // The testnet faucet mints a fixed amount per drip.
            'amount_base_units' => 20_000000,
            'status' => StudentWalletFundingStatus::FUNDED,
            'tx_hash' => null,
            'detail' => null,
            'funded_at' => now(),
        ];
    }

    public function failed(string $detail): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => StudentWalletFundingStatus::FAILED,
            'detail' => $detail,
        ]);
    }
}
