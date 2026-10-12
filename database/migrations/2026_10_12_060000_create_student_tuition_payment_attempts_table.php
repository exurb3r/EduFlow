<?php

declare(strict_types=1);

use App\Enums\StudentTuitionPaymentState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable attempt behind a student's tuition payment.
 *
 * The `request_key` is minted by the server before a signer is reached and is
 * the identity a retry reuses, so a refreshed page, a lost HTTP response or a
 * crash after send converge on the same attempt instead of minting a second
 * one. `provider_handle` is the rail's own identifier for an accepted
 * asynchronous transfer, which is *not* a transaction hash. A hash only
 * arrives once the rail has published one, and neither of them is evidence
 * that money moved until the movement has been verified against the receipt's
 * logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_tuition_payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('tuition_account_id')->constrained('tuition_accounts')->cascadeOnDelete();
            $table->foreignId('student_wallet_id')->constrained('student_wallets')->cascadeOnDelete();

            // The server-side identity a retry must reuse. Minted before a
            // signer could be reached and immutable thereafter.
            $table->uuid('request_key');

            // What the payer authorized, before anything was sent.
            $table->unsignedBigInteger('amount_base_units');
            $table->string('quoted_network_fee');
            $table->unsignedBigInteger('fee_ceiling_base_units');
            $table->unsignedBigInteger('available_base_units');
            $table->unsignedBigInteger('total_base_units');

            // Where the money was to come from and go, read server-side.
            $table->string('payer_address');
            $table->string('recipient_address');
            $table->string('chain');
            $table->unsignedBigInteger('chain_id');

            $table->string('state')->default(StudentTuitionPaymentState::PREPARED->value);
            // Where the attempt got to, in the payer's own terms.
            $table->string('stage')->nullable();
            // The rail's identifier for an accepted transfer. Never a hash.
            $table->string('provider_handle')->nullable();
            // Published once the rail produced one. Still a claim until the
            // movement is verified against the receipt's logs.
            $table->string('provider_reference')->nullable();
            $table->timestamp('provider_accepted_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);

            // Where the attempt stopped being true, if it did.
            $table->string('last_error', 1000)->nullable();
            // The verdict derived for the reference above. Null means no
            // defensible verdict has been derived yet, never "settled".
            $table->string('settlement_verdict')->nullable();
            $table->string('settlement_reason', 1000)->nullable();
            $table->timestamp('settlement_verified_at')->nullable();

            $table->timestamps();

            $table->unique('request_key');
            // At most one open attempt per account: a second one would be a
            // second transfer against the same obligation.
            $table->index(['tuition_account_id', 'state']);
            $table->index(['state', 'attempts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_tuition_payment_attempts');
    }
};
