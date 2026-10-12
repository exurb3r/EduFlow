<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudentWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StudentWallet> */
class StudentWalletFactory extends Factory
{
    protected $model = StudentWallet::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'address' => '0x'.fake()->regexify('[0-9a-f]{40}'),
            'provider' => 'circle',
            'network' => 'arc',
            'chain' => 'ARC-TESTNET',
            'status' => 'active',
            'generated_at' => now(),
        ];
    }
}
