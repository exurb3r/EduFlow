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
        Schema::create('payment_reservation_releases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // One open release proposal per hold; a released hold cannot be proposed twice.
            $table->foreignId('payment_reservation_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 1000);
            $table->char('source_digest', 64);
            $table->char('content_digest', 64);
            $table->json('snapshot');
            $table->char('snapshot_digest', 64);
            $table->timestamps();
            $table->index(['organization_id', 'payment_reservation_id'], 'reservation_releases_hold_index');
        });

        Schema::create('payment_reservation_release_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // Append-only: exactly one terminal decision per proposal, decided once.
            $table->foreignId('payment_reservation_release_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_reservation_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 32);
            $table->string('reason', 1000);
            $table->char('proposal_digest', 64);
            $table->char('review_digest', 64);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER payment_reservation_release_reviews_decision_{$operation} BEFORE {$operation} ON payment_reservation_release_reviews
                    WHEN NEW.decision NOT IN ('approve_release', 'reject', 'hold')
                    BEGIN SELECT RAISE(ABORT, 'Invalid reservation release decision'); END");
            }
        } else {
            DB::statement("ALTER TABLE payment_reservation_release_reviews ADD CONSTRAINT payment_reservation_release_reviews_decision_check
                CHECK (decision IN ('approve_release', 'reject', 'hold'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::unprepared('ALTER TABLE payment_reservation_release_reviews DROP CONSTRAINT payment_reservation_release_reviews_decision_check');
        }

        Schema::dropIfExists('payment_reservation_release_reviews');
        Schema::dropIfExists('payment_reservation_releases');
    }
};
