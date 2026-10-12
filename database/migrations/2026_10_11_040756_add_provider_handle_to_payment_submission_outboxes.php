<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Circle agent wallets are asynchronous, so a submission has a provider handle
 * that is not an on-chain reference.
 *
 * `circle wallet transfer` returns a Circle transaction id (a UUID) rather than
 * a `0x…` hash, and the hash only exists once the transaction reaches a terminal
 * state. Storing that id in `provider_reference` would have been a lie: the
 * reconciler feeds that column straight into `eth_getTransactionReceipt`, and a
 * UUID there is not a hash — it would read as `not_found` and imply a payment
 * had vanished rather than one that is merely still in flight.
 *
 * So the two are kept apart. `provider_handle` is the rail's own identifier and
 * is meaningful only while `state` is `accepted`. `provider_reference` remains
 * the on-chain hash and is set only once it genuinely exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_submission_outboxes', function (Blueprint $table): void {
            $table->string('provider_handle')->nullable()->after('provider_idempotency_key')->index();
        });
    }

    public function down(): void
    {
        Schema::table('payment_submission_outboxes', function (Blueprint $table): void {
            $table->dropColumn('provider_handle');
        });
    }
};
