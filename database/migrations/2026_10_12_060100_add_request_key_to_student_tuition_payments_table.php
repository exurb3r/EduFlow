<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Binds a posted payment to the single attempt that proved it.
 *
 * `request_key` is the durable identity of one payment attempt. Posting is
 * derived from it and from the verified movement, so a second attempt cannot
 * create a second receipt for the same payment and two payments cannot
 * collapse into one row. `applied_base_units` and `overpayment_base_units`
 * split what was received into what settled the debt and what did not: an
 * amount larger than what is owed is a liability to the student, and calling
 * it revenue is how a budget plans money it may have to give back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_tuition_payments', function (Blueprint $table): void {
            $table->string('request_key')->nullable()->after('id');
            $table->unsignedBigInteger('applied_base_units')->default(0)->after('amount_base_units');
            $table->unsignedBigInteger('overpayment_base_units')->default(0)->after('applied_base_units');
            // A rehearsal on a driver that cannot move money. It is never
            // evidence of receipts: nothing is allocated to tuition and nothing
            // is credited to the treasury from it, because nothing settled.
            $table->boolean('simulated')->default(false)->after('tx_verified_on_chain');

            $table->unique('request_key');
        });
    }

    public function down(): void
    {
        Schema::table('student_tuition_payments', function (Blueprint $table): void {
            $table->dropUnique(['request_key']);
            $table->dropColumn(['request_key', 'applied_base_units', 'overpayment_base_units', 'simulated']);
        });
    }
};
