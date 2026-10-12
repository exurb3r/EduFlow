<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Teach the outbox about the `submitted` state.
 *
 * `submitted` is not an outcome the original schema knew about, because the
 * executor did not exist when it was written. It means the rail accepted a
 * reference but nothing has proven money moved: the entry is still owed work,
 * its capacity is still held, and only reconciliation may advance it. Without
 * this the state machine would reject a perfectly ordinary submission as
 * invalid.
 *
 * The constraint is expressed as a trigger on SQLite and a CHECK constraint
 * elsewhere, so this migration widens whichever one the driver actually has.
 */
return new class extends Migration
{
    private const array STATES = ['queued', 'running', 'unknown', 'submitted', 'blocked', 'failed', 'completed', 'paused'];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS payment_submission_outboxes_state');

            $states = implode(', ', array_map(
                fn (string $state): string => "'{$state}'",
                self::STATES,
            ));

            DB::unprepared("CREATE TRIGGER payment_submission_outboxes_state BEFORE UPDATE ON payment_submission_outboxes
                WHEN NEW.state NOT IN ({$states})
                BEGIN SELECT RAISE(ABORT, 'Invalid payment submission state'); END");

            return;
        }

        DB::statement('ALTER TABLE payment_submission_outboxes DROP CONSTRAINT IF EXISTS payment_submission_outboxes_state_check');

        $states = implode(', ', array_map(
            fn (string $state): string => "'{$state}'",
            self::STATES,
        ));

        DB::statement("ALTER TABLE payment_submission_outboxes ADD CONSTRAINT payment_submission_outboxes_state_check
            CHECK (state IN ({$states}))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS payment_submission_outboxes_state');

            DB::unprepared("CREATE TRIGGER payment_submission_outboxes_state BEFORE UPDATE ON payment_submission_outboxes
                WHEN NEW.state NOT IN ('queued', 'running', 'unknown', 'blocked', 'failed', 'completed', 'paused')
                BEGIN SELECT RAISE(ABORT, 'Invalid payment submission state'); END");

            return;
        }

        DB::statement('ALTER TABLE payment_submission_outboxes DROP CONSTRAINT IF EXISTS payment_submission_outboxes_state_check');

        DB::statement("ALTER TABLE payment_submission_outboxes ADD CONSTRAINT payment_submission_outboxes_state_check
            CHECK (state IN ('queued', 'running', 'unknown', 'blocked', 'failed', 'completed', 'paused'))");
    }
};
