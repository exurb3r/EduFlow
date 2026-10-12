<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The immutable link from a Collections receipt to the verified movement behind it.
 *
 * A portal payment becomes cash the moment it settles, so a Collections receipt
 * for it is a *reconciliation* of money already received, never a second
 * arrival. The link is therefore:
 *
 *  - unique, so one payment can be reconciled at most once and two payments can
 *    never collapse into one receipt; and
 *  - nullable, so a cashier-typed receipt keeps working unchanged.
 *
 * `evidence_origin` in the batch snapshot records how a receipt's evidence was
 * produced — by staff attestation or by chain movement — so a reviewer can see
 * which basis they are approving without reading code.
 *
 * SQLite note, and the reason the guards are re-issued below: adding a
 * referenced column to a SQLite table forces Laravel to rebuild it, and the
 * rebuild drops the triggers the original migration installed. Without
 * re-creating them the exact-bounds checks silently stop existing on the test
 * database — the guards that stop a non-integer amount or a reversed period
 * from being written at all.
 */
return new class extends Migration
{
    public function down(): void
    {
        Schema::table('collection_batches', function (Blueprint $table): void {
            $table->dropUnique(['student_tuition_payment_id']);
            $table->dropForeign(['student_tuition_payment_id']);
            $table->dropColumn('student_tuition_payment_id');
        });
    }

    public function up(): void
    {
        Schema::table('collection_batches', function (Blueprint $table): void {
            $table->foreignId('student_tuition_payment_id')
                ->nullable()
                ->after('prepared_by')
                ->constrained('student_tuition_payments')
                ->restrictOnDelete();

            $table->unique('student_tuition_payment_id', 'collections_tuition_payment_unique');
        });

        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::unprepared("CREATE TRIGGER IF NOT EXISTS collection_batches_bounds_{$operation} BEFORE {$operation} ON collection_batches
                WHEN typeof(NEW.received_minor_units) != 'integer' OR typeof(NEW.restricted_minor_units) != 'integer'
                    OR NEW.received_minor_units <= 0 OR NEW.restricted_minor_units < 0 OR NEW.restricted_minor_units > NEW.received_minor_units
                    OR NEW.collected_from >= NEW.collected_until OR NEW.currency NOT IN ('USDC', 'USD', 'PHP', 'EUR', 'GBP', 'CAD', 'SGD', 'INR')
                BEGIN SELECT RAISE(ABORT, 'Invalid exact collection bounds'); END");
        }
    }
};
