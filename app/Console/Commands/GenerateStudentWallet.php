<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Student;
use App\Services\CircleAgentWalletCli;
use App\Services\StudentWalletGenerationService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Mint a Circle agent wallet for a student and point the payout address at it.
 *
 * Circle allows five agent wallets per blockchain with no way to release one, so
 * this is a one-way spend of a slot. It refuses when the student already has a
 * wallet, and it prints the remaining slot count on every run so the ceiling is
 * never a surprise.
 */
class GenerateStudentWallet extends Command
{
    protected $signature = 'student:generate-wallet {student? : Student ID or student number} {--list : Show existing student wallets and remaining slots}';

    protected $description = 'Generate a Circle agent wallet for a student and set it as the payout address.';

    public function handle(StudentWalletGenerationService $service): int
    {
        $this->newLine();
        $this->line('  Circle agent wallets · '.$service->chain());
        $this->line('  '.str_repeat('─', 64));
        $this->line(sprintf('  %d of %d slots used, %d remaining',
            CircleAgentWalletCli::AGENT_WALLET_LIMIT - $service->remainingSlots(),
            CircleAgentWalletCli::AGENT_WALLET_LIMIT,
            $service->remainingSlots(),
        ));
        $this->newLine();

        // The console hands the argument back as an int, not a string.
        $identifier = (string) $this->argument('student');

        if ($identifier === '') {
            $this->line('  Usage: php artisan student:generate-wallet <id|STU-000001>');
            $this->line('          php artisan student:generate-wallet --list');

            return self::SUCCESS;
        }

        $student = ctype_digit($identifier)
            ? Student::query()->find((int) $identifier)
            : Student::query()->where('student_number', $identifier)->first();

        if (! $student instanceof Student) {
            $this->error("No student found for [{$identifier}].");

            return self::FAILURE;
        }

        try {
            $wallet = $service->generate($student);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Generated {$wallet->address} for {$student->student_number}.");
        $this->line("  Payout address is now {$student->fresh()->payout_address}");
        $this->line("  {$service->remainingSlots()} agent-wallet slot(s) left on {$service->chain()}");
        $this->line('  Fund it with: php artisan student:fund-wallet '.$student->getKey());

        return self::SUCCESS;
    }
}
