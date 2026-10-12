<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One reservation per bill, forever, is not an invariant worth keeping.
 *
 * `payment_reservations` is append-only evidence: a hold that is reviewed and
 * released still exists, because the capacity arithmetic has to reproduce it.
 * With `UNIQUE(invoice_id)` that row permanently blocked a fresh hold on the
 * same bill, which made the release route unreachable in practice -- release a
 * hold and the bill can never be reserved again, so the money it was holding is
 * stranded by the very act meant to free it.
 *
 * This is the same over-broad uniqueness already removed from
 * `funding_window_approvals.organization_id` and from mandate reviews, for the
 * same reason: the constraint belongs in code, where "at most one hold that is
 * still consuming capacity" can be stated precisely, and where append-only
 * history is allowed to keep the rows that explain it.
 *
 * `payment_intent_id` stays unique. That one is load-bearing: a single draft may
 * be held exactly once, and replaying a reservation returns the recorded hold
 * rather than minting a second one.
 *
 * The live-hold invariant itself is enforced in `ReserveVendorPayment`, which
 * admits a new hold only when no unreleased hold exists for the bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_reservations', function (Blueprint $table): void {
            $table->dropUnique(['invoice_id']);
        });
    }

    public function down(): void
    {
        // Only safe while every bill still has at most one reservation. This is
        // recorded rather than assumed: restoring the constraint on history
        // that legitimately holds more than one row would fail.
        $duplicates = DB::table('payment_reservations')
            ->select('invoice_id', DB::raw('count(*) as total'))
            ->groupBy('invoice_id')
            ->having('total', '>', 1)
            ->limit(1);

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot restore UNIQUE(invoice_id): released-and-re-reserved bills now hold more than one reservation row.'
            );
        }

        Schema::table('payment_reservations', function (Blueprint $table): void {
            $table->unique('invoice_id');
        });
    }
};
