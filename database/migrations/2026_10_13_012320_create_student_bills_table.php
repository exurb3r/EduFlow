<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A non-tuition charge a student owes the institution — the "miscellaneous
 * fee" of a Philippine college, and in this application the same thing
 * denominated in exact 6-decimal USDC base units.
 *
 * Deliberately NOT a `transactions` row: recording a bill says a debt exists,
 * not that money arrived. Amounts are integers because a float cannot hold
 * 6-decimal base units without drift, and the same bill must not be usable as
 * evidence that a payment settled.
 *
 * No unique on (student_id, category): one student legitimately owes several
 * laboratory charges, and several registrations, in a single term. The
 * composite index on (student_id, status) serves the only read this table has
 * so far — "what does this student still owe" — and its leading column
 * covers the per-student lookup.
 *
 * Neither foreign key cascades, like `tuition_accounts`: a bill is a claim the
 * institution holds, so removing a student or a term must be refused rather
 * than silently drop it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('academic_term_id')->nullable()->constrained('academic_terms');
            $table->string('category');
            $table->string('description');
            $table->unsignedBigInteger('amount_base_units');
            $table->unsignedBigInteger('paid_base_units')->default(0);
            $table->date('due_date')->nullable();
            $table->string('status')->default('open');
            $table->timestamps();

            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_bills');
    }
};
