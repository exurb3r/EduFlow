<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudentTuitionPayment;
use App\Models\StudentWallet;
use App\Models\TuitionAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StudentTuitionPayment> */
class StudentTuitionPaymentFactory extends Factory
{
    protected $model = StudentTuitionPayment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'tuition_account_id' => TuitionAccount::factory(),
            'student_wallet_id' => StudentWallet::factory(),
            'tx_hash' => '0x'.fake()->regexify('[0-9a-f]{64}'),
            'amount_base_units' => 5_000000,
            // A realistic Arc-native gas fee, at 18 decimals.
            'network_fee' => '0.02945113436514256',
            'payer_address' => '0x'.fake()->regexify('[0-9a-f]{40}'),
            'recipient_address' => '0x'.fake()->regexify('[0-9a-f]{40}'),
            'chain' => 'ARC-TESTNET',
            'tx_verified_on_chain' => true,
            'executed_at' => now(),
        ];
    }
}
