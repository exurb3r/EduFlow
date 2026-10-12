<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let one institution retire an approval when a window is rolled over.
 *
 * `funding_window_approvals.organization_id` was unique, and its comment said
 * it held only "until reviewed rollover/allocation semantics exist". Those
 * semantics now exist, so the constraint goes: a rollover successor must be
 * able to hold the institution's approval alongside the retired approval of
 * the window it replaces. Both rows are evidence, and rows cannot be deleted,
 * so retirement is a state transition rather than a removal.
 *
 * The invariant that uniqueness was protecting is now held in code: at most one
 * approval may be live per institution, and a new one is refused unless it is
 * the reviewed successor of the window the current approval belongs to. That
 * check runs inside the same institution row lock the action already takes, so
 * two concurrent approvals cannot both pass it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_window_approvals', function (Blueprint $table): void {
            $table->dropUnique(['organization_id']);
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('superseded_by_approval_id')->nullable()
                ->constrained('funding_window_approvals')
                ->restrictOnDelete();
        });

        Schema::table('funding_window_approvals', function (Blueprint $table): void {
            $table->index(['organization_id', 'funding_window_id'], 'funding_window_approvals_window_index');
            $table->index(['organization_id', 'superseded_at'], 'funding_window_approvals_live_index');
        });
    }

    public function down(): void
    {
        Schema::table('funding_window_approvals', function (Blueprint $table): void {
            $table->dropIndex('funding_window_approvals_live_index');
            $table->dropIndex('funding_window_approvals_window_index');
            $table->dropConstrainedForeignId('superseded_by_approval_id');
            $table->dropColumn('superseded_at');
            $table->unique(['organization_id']);
        });
    }
};
