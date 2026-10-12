<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_submission_outboxes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // One authorized payment is owed to the rail exactly once.
            $table->foreignId('payment_authorization_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_intent_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            // Stable across every retry: the provider must recognise a repeat
            // of this key as the same payment, not a second one.
            $table->string('provider_idempotency_key')->unique();
            $table->string('state', 32)->default('queued');
            $table->string('stage', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->string('last_error', 1000)->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->json('result')->nullable();
            $table->char('result_digest', 64)->nullable();
            $table->json('snapshot');
            $table->char('snapshot_digest', 64);
            $table->timestamps();
            $table->index(['organization_id', 'state'], 'submission_outbox_state_index');
        });

        Schema::create('payment_submission_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_submission_outbox_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('provider_idempotency_key');
            $table->string('outcome', 32);
            $table->string('provider_reference', 255)->nullable();
            $table->json('request_snapshot');
            $table->json('response_snapshot')->nullable();
            $table->timestamp('observed_at');
            $table->timestamps();
            // Attempt numbering is dense per entry: a gap means a lost write.
            $table->unique(['payment_submission_outbox_id', 'attempt_number'], 'submission_attempt_number_unique');
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER payment_submission_outboxes_state BEFORE UPDATE ON payment_submission_outboxes
                WHEN NEW.state NOT IN ('queued', 'running', 'unknown', 'blocked', 'failed', 'completed', 'paused')
                BEGIN SELECT RAISE(ABORT, 'Invalid payment submission state'); END");

            DB::unprepared("CREATE TRIGGER payment_submission_attempts_outcome BEFORE INSERT ON payment_submission_attempts
                WHEN NEW.outcome NOT IN ('submitted', 'unknown', 'failed', 'blocked')
                BEGIN SELECT RAISE(ABORT, 'Invalid payment submission attempt outcome'); END");

            DB::unprepared("CREATE TRIGGER payment_submission_outboxes_attempts BEFORE UPDATE ON payment_submission_outboxes
                WHEN NEW.attempts < OLD.attempts OR NEW.attempts > OLD.max_attempts
                BEGIN SELECT RAISE(ABORT, 'Invalid payment submission attempt bound'); END");
        } else {
            DB::statement('ALTER TABLE payment_submission_outboxes ADD CONSTRAINT payment_submission_outboxes_state_check
                CHECK (state IN (\'queued\', \'running\', \'unknown\', \'blocked\', \'failed\', \'completed\', \'paused\'))');
            DB::statement('ALTER TABLE payment_submission_attempts ADD CONSTRAINT payment_submission_attempts_outcome_check
                CHECK (outcome IN (\'submitted\', \'unknown\', \'failed\', \'blocked\'))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::unprepared('ALTER TABLE payment_submission_attempts DROP CONSTRAINT payment_submission_attempts_outcome_check');
            DB::unprepared('ALTER TABLE payment_submission_outboxes DROP CONSTRAINT payment_submission_outboxes_state_check');
        }

        Schema::dropIfExists('payment_submission_attempts');
        Schema::dropIfExists('payment_submission_outboxes');
    }
};
