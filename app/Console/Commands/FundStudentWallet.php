<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\StudentWallet;
use App\Services\StudentWalletGenerationService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Drip the Arc testnet faucet into a student's generated wallet.
 *
 * The faucet mints a fixed 20 USDC per drip and rate-limits after a few, so each
 * run spends something that cannot be undone and cannot be immediately repeated.
 * The command therefore defaults to listing what would be funded and requires
 * an explicit student or --all to actually drip.
 */
class FundStudentWallet extends Command
{
    protected $signature = 'student:fund-wallet {student? : Student ID or student number} {--all : Fund every student wallet that has one} {--list : Show funded wallets without dripping}';

    protected $description = 'Top up a student\'s generated Circle wallet from the Arc testnet faucet.';

    public function handle(StudentWalletGenerationService $service): int
    {
        if ($this->option('list')) {
            return $this->listWallets($service);
        }

        if (! $this->option('all') && ! $this->hasStudentSelector()) {
            $this->line('  No student given, so nothing was funded.');
            $this->line('  Usage: php artisan student:fund-wallet <id|STU-000001>  ·  --all  ·  --list');
            $this->newLine();

            return $this->listWallets($service);
        }

        $students = $this->option('all')
            ? Student::query()->whereHas('wallet')->with('wallet')->get()->all()
            : $this->studentsFromArgument();

        if ($students === null) {
            return self::FAILURE;
        }

        if ($students === []) {
            $this->warn('No student has a generated wallet yet. Generate one with `php artisan student:generate-wallet <student>`.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($students as $student) {
            try {
                $funding = $service->fund($student);
            } catch (RuntimeException $e) {
                $this->error("{$student->student_number}: {$e->getMessage()}");
                $failures++;

                continue;
            }

            if ($funding->status->value === 'funded') {
                $this->info("{$student->student_number}: funded {$funding->amount()} on {$funding->chain}.");
            } else {
                $this->warn("{$student->student_number}: faucet refused — {$funding->detail}");
                $failures++;
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '  %d funded, %d failed · %d agent-wallet slot(s) left on %s',
            count($students) - $failures,
            $failures,
            $service->remainingSlots(),
            $service->chain(),
        ));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function hasStudentSelector(): bool
    {
        $identifier = $this->argument('student');

        return $identifier !== null && $identifier !== '';
    }

    /**
     * The named student, or null when nothing matched.
     *
     * @return array<int, Student>|null
     */
    private function studentsFromArgument(): ?array
    {
        $identifier = (string) $this->argument('student');

        $student = ctype_digit($identifier)
            ? Student::query()->find((int) $identifier)
            : Student::query()->where('student_number', $identifier)->first();

        if (! $student instanceof Student) {
            $this->error("No student found for [{$identifier}].");

            return null;
        }

        return [$student];
    }

    private function listWallets(StudentWalletGenerationService $service): int
    {
        $this->newLine();
        $this->line('  Generated student wallets');
        $this->line('  '.str_repeat('─', 72));

        // Eager-loaded, so a missing student reads as null rather than a query.
        $wallets = StudentWallet::query()->with(['student.user', 'fundings'])->latest('id')->get();

        if ($wallets->isEmpty()) {
            $this->line('  None yet. Generate one with `php artisan student:generate-wallet <student>`.');

            return self::SUCCESS;
        }

        foreach ($wallets as $wallet) {
            $latest = $wallet->latestFunding();
            $student = $wallet->student;

            $this->line(sprintf(
                '  %-12s %s',
                $student->student_number ?? '—',
                $wallet->address,
            ));
            $this->line(sprintf(
                '  %-12s %s · generated %s%s',
                '',
                $wallet->chain,
                $wallet->generated_at->diffForHumans(),
                $latest === null ? '' : ' · last drip '.$latest->funded_at->diffForHumans().' ('.$latest->status->value.')',
            ));
            $this->newLine();
        }

        $this->line(sprintf(
            '  %d wallet(s) on %s · %d agent-wallet slot(s) left',
            $wallets->count(),
            $service->chain(),
            $service->remainingSlots(),
        ));

        return self::SUCCESS;
    }
}
