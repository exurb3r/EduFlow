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
        Schema::table('collection_batches', function (Blueprint $table): void {
            $table->foreignId('student_bill_payment_id')->nullable()->constrained('student_bill_payments')->restrictOnDelete();
            $table->unique('student_bill_payment_id', 'collections_bill_payment_unique');
        });

        $this->restoreSqliteBounds();
    }

    public function down(): void
    {
        Schema::table('collection_batches', function (Blueprint $table): void {
            $table->dropUnique('collections_bill_payment_unique');
            $table->dropForeign(['student_bill_payment_id']);
            $table->dropColumn('student_bill_payment_id');
        });

        $this->restoreSqliteBounds();
    }

    /** SQLite rebuilds referenced tables and drops their exact-money triggers. */
    private function restoreSqliteBounds(): void
    {
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
