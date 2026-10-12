<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per faucet drip.
 *
 * The Arc testnet faucet mints a fixed amount and rate-limits hard, so the
 * history is the only way to know when a wallet was last topped up or whether
 * a drip already failed. It is a mint, not a transfer: nothing debits the
 * treasury when this runs, so it deliberately never writes a Transaction row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_wallet_fundings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_wallet_id')->constrained('student_wallets')->cascadeOnDelete();
            $table->string('chain');
            $table->unsignedBigInteger('amount_base_units');
            $table->string('status');
            $table->string('tx_hash')->nullable();
            $table->text('detail')->nullable();
            $table->timestamp('funded_at');
            $table->timestamps();

            $table->index(['student_wallet_id', 'funded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_wallet_fundings');
    }
};
