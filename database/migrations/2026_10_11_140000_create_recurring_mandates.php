<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The standing-mandate chain: what a human authorized in advance, who verified it,
 * and which individual occurrences it has actually released.
 *
 * Three documents, deliberately separate. The mandate is the class of payment a
 * human authorized. The review is the maker/checker record of a different human
 * confirming it — neither may be the same person, and no agent may review at
 * all. The occurrence is one recurring obligation that the mandate released.
 *
 * None of these grants execution authority. Every `evidence()` on these models
 * returns `can_execute: false`, and the amounts are integers in USDC base units
 * rather than floats, because a ceiling that drifts is not a ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_mandates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_destination_version_id')->constrained('vendor_destination_versions')->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('finance_policy_version_id')->constrained()->restrictOnDelete();
            // The business-approved obligation the mandate speaks for. An LLM
            // must never infer that a bill is recurring; a human names it.
            $table->string('obligation_reference', 255);
            $table->char('obligation_digest', 64);
            $table->string('chain', 32);
            $table->unsignedBigInteger('chain_id');
            $table->string('currency', 8)->default('USDC');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('due_window_days');
            // Every allowance starts at zero. Enabling a nonzero ceiling is a
            // separate, reviewed act, never a default.
            $table->unsignedBigInteger('per_occurrence_ceiling_base_units');
            $table->unsignedBigInteger('fee_ceiling_base_units');
            $table->unsignedBigInteger('daily_limit_base_units');
            $table->unsignedBigInteger('period_limit_base_units');
            $table->string('state', 32)->default('draft');
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->json('snapshot');
            $table->char('snapshot_digest', 64);
            $table->timestamps();
            $table->index(['organization_id', 'state'], 'recurring_mandates_state_index');
            $table->index(['organization_id', 'budget_id'], 'recurring_mandates_budget_index');
            // One live mandate per vendor obligation per institution, so two
            // concurrent scopes cannot both claim the same recurring bill.
            $table->unique(['organization_id', 'vendor_id', 'obligation_digest', 'state'], 'recurring_mandates_scope_unique');
        });

        Schema::create('recurring_mandate_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('recurring_mandate_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 32);
            $table->string('reason', 1000);
            $table->char('mandate_digest', 64);
            $table->char('review_digest', 64);
            $table->timestamps();
        });

        Schema::create('mandate_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('recurring_mandate_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_intent_id')->nullable()->constrained()->restrictOnDelete();
            // The obligation plus its occurrence is the identity. A repeat of
            // the same pair is the same occurrence, never a second payment.
            $table->char('occurrence_digest', 64);
            $table->unsignedBigInteger('amount_base_units');
            $table->string('chain', 32);
            $table->unsignedBigInteger('chain_id');
            $table->timestamp('due_at');
            $table->string('disposition', 32)->default('evaluated');
            $table->string('reason', 1000)->nullable();
            $table->json('checks')->nullable();
            $table->char('checks_digest', 64)->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'recurring_mandate_id'], 'mandate_occurrences_mandate_index');
            // No prior fulfilment: the same obligation occurrence cannot be
            // recorded twice under one mandate, which is what makes the
            // uniqueness meaningful.
            $table->unique(['recurring_mandate_id', 'occurrence_digest'], 'mandate_occurrences_identity_unique');
        });

        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER recurring_mandates_state_{$operation} BEFORE {$operation} ON recurring_mandates
                    WHEN NEW.state NOT IN ('draft', 'approved', 'revoked', 'expired')
                    BEGIN SELECT RAISE(ABORT, 'Invalid recurring mandate state'); END");

                DB::unprepared("CREATE TRIGGER recurring_mandate_reviews_decision_{$operation} BEFORE {$operation} ON recurring_mandate_reviews
                    WHEN NEW.decision NOT IN ('approve_mandate', 'reject', 'hold', 'revoke')
                    BEGIN SELECT RAISE(ABORT, 'Invalid recurring mandate decision'); END");

                DB::unprepared("CREATE TRIGGER mandate_occurrences_disposition_{$operation} BEFORE {$operation} ON mandate_occurrences
                    WHEN NEW.disposition NOT IN ('release', 'escalate', 'blocked')
                    BEGIN SELECT RAISE(ABORT, 'Invalid mandate occurrence disposition'); END");
            }
        } else {
            DB::statement("ALTER TABLE recurring_mandates ADD CONSTRAINT recurring_mandates_state_check
                CHECK (state IN ('draft', 'approved', 'revoked', 'expired'))");
            DB::statement('ALTER TABLE recurring_mandate_reviews ADD CONSTRAINT recurring_mandate_reviews_decision_check
                CHECK (decision IN (\'approve_mandate\', \'reject\', \'hold\', \'revoke\'))');
            DB::statement('ALTER TABLE mandate_occurrences ADD CONSTRAINT mandate_occurrences_disposition_check
                CHECK (disposition IN (\'release\', \'escalate\', \'blocked\'))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE mandate_occurrences DROP CONSTRAINT mandate_occurrences_disposition_check');
            DB::statement('ALTER TABLE recurring_mandate_reviews DROP CONSTRAINT recurring_mandate_reviews_decision_check');
            DB::statement('ALTER TABLE recurring_mandates DROP CONSTRAINT recurring_mandates_state_check');
        }

        Schema::dropIfExists('mandate_occurrences');
        Schema::dropIfExists('recurring_mandate_reviews');
        Schema::dropIfExists('recurring_mandates');
    }
};
