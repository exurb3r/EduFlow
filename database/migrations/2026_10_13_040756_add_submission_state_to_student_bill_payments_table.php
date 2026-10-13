<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rail-facing state of a bill payment, kept on the payment row itself.
 *
 * A separate attempts table would duplicate the identity and the consent that are
 * already bound here, and would let the two disagree about whether a transfer was
 * ever sent. So the submission lives here, and the columns are chosen to make the
 * dangerous states unrepresentable rather than merely unlikely:
 *
 *  - `submitted_at` is the write-ahead fence. It is set inside the claim
 *    transaction, before any signer can be reached, so a crash cannot leave a
 *    sent transfer looking unsent.
 *  - `provider_handle` and `provider_reference` are different columns because a
 *    Circle agent wallet returns a transaction *id*, not a hash. Collapsing them
 *    would make an in-flight payment indistinguishable from a settled one.
 *  - `fee_guarantee` records which of the two different fee facts was relied on,
 *    because "the rail paid the fee" and "the transaction capped the fee" imply
 *    different arithmetic and a reader must be able to tell them apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_bill_payments', function (Blueprint $table): void {
            $table->string('submission_state', 32)->default('consented');
            $table->uuid('provider_handle')->nullable();
            $table->string('provider_reference', 66)->nullable();
            $table->string('fee_guarantee', 32)->nullable();
            $table->unsignedSmallInteger('observation_count')->default(0);
            $table->timestamp('leased_until')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();

            $table->index(['submission_state', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Bill payment submission evidence is append-only; use a forward migration.');
    }
};
