<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Teach the append-only attempt log about the `accepted` outcome.
 *
 * The attempt log predates asynchronous rails and knows four outcomes. An
 * acceptance is a fifth, and it is the one that most needs recording rather than
 * folding into another: it means the rail has taken the payment and told us its
 * transaction id, while no on-chain reference exists.
 *
 * The model, not just this constraint, requires an accepted attempt to name the
 * rail handle that matches its entry. The constraint only admits the value; the
 * binding is enforced where it can be read.
 */
return new class extends Migration
{
    private const array OUTCOMES = ['accepted', 'submitted', 'unknown', 'failed', 'blocked'];

    public function up(): void
    {
        $outcomes = implode(', ', array_map(fn (string $outcome): string => "'{$outcome}'", self::OUTCOMES));

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS payment_submission_attempts_outcome');

            DB::unprepared("CREATE TRIGGER payment_submission_attempts_outcome BEFORE INSERT ON payment_submission_attempts
                WHEN NEW.outcome NOT IN ({$outcomes})
                BEGIN SELECT RAISE(ABORT, 'Invalid payment submission attempt outcome'); END");

            return;
        }

        DB::statement('ALTER TABLE payment_submission_attempts DROP CONSTRAINT IF EXISTS payment_submission_attempts_outcome_check');

        DB::statement("ALTER TABLE payment_submission_attempts ADD CONSTRAINT payment_submission_attempts_outcome_check
            CHECK (outcome IN ({$outcomes}))");
    }

    public function down(): void
    {
        $outcomes = "'submitted', 'unknown', 'failed', 'blocked'";

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS payment_submission_attempts_outcome');

            DB::unprepared("CREATE TRIGGER payment_submission_attempts_outcome BEFORE INSERT ON payment_submission_attempts
                WHEN NEW.outcome NOT IN ({$outcomes})
                BEGIN SELECT RAISE(ABORT, 'Invalid payment submission attempt outcome'); END");

            return;
        }

        DB::statement('ALTER TABLE payment_submission_attempts DROP CONSTRAINT IF EXISTS payment_submission_attempts_outcome_check');

        DB::statement("ALTER TABLE payment_submission_attempts ADD CONSTRAINT payment_submission_attempts_outcome_check
            CHECK (outcome IN ({$outcomes}))");
    }
};
