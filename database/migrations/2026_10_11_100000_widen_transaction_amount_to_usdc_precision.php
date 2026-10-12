<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hold USDC at its own precision in the transaction ledger.
 *
 * `transactions.amount` was declared `decimal(15,2)`, which cannot represent a
 * 6-decimal USDC amount: a vendor bill of 25.000001 USDC round-trips through
 * that column as 25.00. That is a silently wrong money figure sitting in the
 * ledger, and it is the kind of rounding the rest of this codebase is careful
 * to avoid elsewhere — PaymentIntent, InvoiceVersion and the reservations all
 * carry exact integer base units.
 *
 * Widening the column is backward compatible for readers (every consumer casts
 * to float for display), and it lets a verified settlement be mirrored exactly
 * rather than refused or rounded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->decimal('amount', 24, 6)->change();
        });
    }

    public function down(): void
    {
        // Not reversible without losing precision: narrowing back would
        // silently truncate any 6-decimal amount already recorded.
        throw new RuntimeException('Narrowing transactions.amount back to 2 decimals would truncate recorded USDC amounts; write a compensating correction instead.');
    }
};
