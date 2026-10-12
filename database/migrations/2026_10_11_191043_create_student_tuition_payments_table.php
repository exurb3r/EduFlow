<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A real USDC transfer from a student's wallet into the institution treasury.
 *
 * Distinct from a `transactions` row, which is the institution's ledger: this
 * is the student's receipt, so it carries what they paid, the network fee they
 * also paid, and the on-chain proof.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_tuition_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('tuition_account_id')->constrained('tuition_accounts')->cascadeOnDelete();
            $table->foreignId('student_wallet_id')->constrained('student_wallets')->cascadeOnDelete();
            $table->string('tx_hash');
            $table->unsignedBigInteger('amount_base_units');
            // Arc pays gas in native USDC at 18 decimals, so the fee is stored
            // as an exact decimal string. A bigint of 18-decimal units overflows
            // on plausible fees, and a float would round them.
            $table->string('network_fee');
            $table->string('payer_address');
            $table->string('recipient_address');
            $table->string('chain');
            $table->boolean('tx_verified_on_chain')->default(false);
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['student_id', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_tuition_payments');
    }
};
