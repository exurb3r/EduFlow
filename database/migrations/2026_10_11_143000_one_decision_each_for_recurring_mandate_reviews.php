<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a mandate be decided once per decision, rather than only once ever.
 *
 * Approval is decided once and forever, but revocation has to come later: a
 * contract ends, a vendor changes, a cap is withdrawn. With a single unique key
 * on the mandate, a revocation had no way to be recorded, so an autonomous lane
 * could not be switched off through the same record that switched it on.
 *
 * The key is now the pair, so approving twice is still refused and revoking
 * twice is still refused, while approving and then revoking is the intended
 * lifecycle. Each row remains append-only evidence of one human decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_mandate_reviews', function (Blueprint $table): void {
            $table->dropUnique(['recurring_mandate_id']);
            $table->unique(['recurring_mandate_id', 'decision'], 'recurring_mandate_reviews_decision_unique');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_mandate_reviews', function (Blueprint $table): void {
            $table->dropUnique('recurring_mandate_reviews_decision_unique');
            $table->unique(['recurring_mandate_id']);
        });
    }
};
