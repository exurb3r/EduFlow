<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Teach the outbox about the `accepted` state.
 *
 * `accepted` is what an asynchronous rail produces. Circle agent wallets return
 * a transaction id from `circle wallet transfer`, not an on-chain hash, and the
 * hash only appears once the transaction completes. Without a state for that
 * gap the machine would have to call it `submitted` — which claims a reference
 * exists — or `unknown` — which claims we do not know whether a transfer
 * happened. Both are false: the rail has told us it took the payment, and told
 * us nothing about whether the money moved.
 *
 * `accepted` is open work with capacity still held, but it is **not**
 * dispatchable: the rail already owns the transfer, so a second submission
 * would be a double payment.
 */
return new class extends Migration
{
    private const array STATES = ['queued', 'running', 'unknown', 'accepted', 'submitted', 'blocked', 'failed', 'completed', 'paused'];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS payment_submission_outboxes_state');

            $states = implode(', ', array_map(fn (string $state): string => "'{$state}'", self::STATES));

            DB::unprepared("CREATE TRIGGER payment_submission_outboxes_state BEFORE UPDATE ON payment_submission_outboxes
                WHEN NEW.state NOT IN ({$states})
                BEGIN SELECT RAISE(ABORT, 'Invalid payment submission state'); END");

            return;
        }

        DB::statement('ALTER TABLE payment_submission_outboxes DROP CONSTRAINT IF EXISTS payment_submission_outboxes_state_check');

        $states = implode(', ', array_map(fn (string $state): string => "'{$state}'", self::STATES));

        DB::statement("ALTER TABLE payment_submission_outboxes ADD CONSTRAINT payment_submission_outboxes_state_check
            CHECK (state IN ({$states}))");
    }

    public function down(): void
    {
        $states = implode(', ', array_map(
            fn (string $state): string => "'{$state}'",
            ['queued', 'running', 'unknown', 'submitted', 'blocked', 'failed', 'completed', 'paused'],
        ));

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS payment_submission_outboxes_state');

            DB::unprepared("CREATE TRIGGER payment_submission_outboxes_state BEFORE UPDATE ON payment_submission_outboxes
                WHEN NEW.state NOT IN ({$states})
                BEGIN SELECT RAISE(ABORT, 'Invalid payment submission state'); END");

            return;
        }

        DB::statement('ALTER TABLE payment_submission_outboxes DROP CONSTRAINT IF EXISTS payment_submission_outboxes_state_check');

        DB::statement("ALTER TABLE payment_submission_outboxes ADD CONSTRAINT payment_submission_outboxes_state_check
            CHECK (state IN ({$states}))");
    }
};
