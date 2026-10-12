<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A supervisor's later view on an automatically released occurrence.
 *
 * This is **retrospective feedback, not approval** (§18.6). An automatic
 * release had no per-item approver, so this record cannot and does not create
 * one. It answers a narrower question: shown the decision afterwards, would
 * this person have made the same call?
 *
 * It is deliberately per-occurrence and single-valued, so a repeated review
 * cannot inflate any rate, and it is append-only so a disagreement cannot be
 * quietly overwritten by a later agreement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mandate_retrospective_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('mandate_occurrence_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            // agreed | would_have_escalated | would_have_declined
            $table->string('verdict', 32);
            $table->string('comment', 1000)->nullable();
            $table->char('occurrence_digest', 64);
            $table->char('review_digest', 64);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER mandate_retrospective_reviews_verdict_{$operation} BEFORE {$operation} ON mandate_retrospective_reviews
                    WHEN NEW.verdict NOT IN ('agreed', 'would_have_escalated', 'would_have_declined')
                    BEGIN SELECT RAISE(ABORT, 'Invalid retrospective verdict'); END");
            }
        } else {
            DB::statement('ALTER TABLE mandate_retrospective_reviews ADD CONSTRAINT mandate_retrospective_reviews_verdict_check
                CHECK (verdict IN (\'agreed\', \'would_have_escalated\', \'would_have_declined\'))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE mandate_retrospective_reviews DROP CONSTRAINT mandate_retrospective_reviews_verdict_check');
        }

        Schema::dropIfExists('mandate_retrospective_reviews');
    }
};
