<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A student's payment of one of their own non-tuition bills.
 *
 * Deliberately NOT a `transactions` row. The legacy float ledger is the
 * institution's record of its own money; this is the student's receipt for a
 * charge the registrar levied, and it must never be readable as a second
 * arrival of the same cash.
 *
 * ## Why one table holds both the quote and the receipt
 *
 * The tuition lane splits those two into `student_tuition_payment_intents` and
 * `student_tuition_payments`. A bill payment cannot: its quote is bound to a
 * `student_bills` row and to no tuition account at all, so
 * `student_tuition_payment_intents` — whose `hasValidSnapshot()` re-derives a
 * `tuition_account_id`, a `student_tuition_testnet_demo` purpose and a pilot
 * wallet designation — can never be valid for a bill. Reusing it would mean
 * binding a bill to an unrelated tuition account purely to satisfy a
 * validator, which is the kind of lie this schema exists to prevent.
 *
 * So the row carries its own three immutable layers instead, in the order they
 * are earned, and each one is separately re-derivable:
 *
 *   1. `snapshot` + `snapshot_digest` — written before any signer could be
 *      reached. This is the consent record, and it is what the payer echoes.
 *   2. `consent_snapshot` + `consent_digest` + `consented_at` — the acceptance
 *      of that exact snapshot, bound to it by digest.
 *   3. `settlement_evidence` + `settlement_evidence_digest` — persisted chain
 *      proof, written once and never rewritten.
 *
 * A row with no consent is a quote, with consent but no proof is a promise, and
 * only `allocated_at` set means money settled a debt. Nothing about the row's
 * existence is evidence of a payment; `tx_verified_on_chain` and the evidence
 * digest are.
 *
 * ## Identity and fences
 *
 * `request_key` is the server-minted key the payer consented under and is
 * unique, so one consent can never become two receipts. `movement_key` is a
 * digest of the on-chain movement itself (chain, hash, sender, recipient,
 * amount) and is unique too: the same settled transfer cannot be posted twice
 * under two different consents, which is the specific failure mode that would
 * otherwise credit one bill twice.
 *
 * `submitted_at` is a write-once fence set before any external call, so a retry
 * after a lost response observes rather than resends. `allocated_at` is the
 * write-once allocation fence, and it is the only thing that moves
 * `student_bills.paid_base_units`.
 *
 * ## Money
 *
 * All three amounts are unsigned integers of 6-decimal USDC base units.
 * `network_fee` is a string because Arc pays gas in NATIVE USDC at 18
 * decimals: a bigint of 18-decimal units overflows on a plausible fee and a
 * float would silently round it. It is null until the chain reports one, and a
 * quote's fee lives in the snapshot as `quoted_network_fee` so it can never be
 * mistaken for the fee that was actually charged.
 *
 * `applied_base_units` + `overpayment_base_units` always re-sum to
 * `amount_base_units`. The split is derived at allocation from what the bill
 * still owed, because an amount larger than the remaining debt is a liability
 * held for the student, not settled debt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_bill_payments', function (Blueprint $table): void {
            $table->id();

            // The identity the payer consented under. Server-minted, never taken
            // from a form, so a crafted post cannot collide with a real consent.
            $table->uuid('request_key')->unique();

            // Digest of the settled movement, so one transfer posts once.
            $table->string('movement_key')->nullable()->unique();

            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('quoted_for')->constrained('users')->restrictOnDelete();
            $table->foreignId('student_bill_id')->constrained()->restrictOnDelete();
            // Restricts rather than cascades: a receipt is evidence. This mirrors
            // `student_bills`, which also refuses to be orphaned by a student
            // removal, so a cascade here would be unreachable anyway.
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_wallet_id')->constrained()->restrictOnDelete();

            $table->json('snapshot');
            $table->char('snapshot_digest', 64);
            $table->json('consent_snapshot')->nullable();
            $table->char('consent_digest', 64)->nullable();
            $table->timestamp('consented_at')->nullable();

            // Write-once fence, set before any external call is made.
            $table->timestamp('submitted_at')->nullable();

            $table->json('settlement_evidence')->nullable();
            $table->char('settlement_evidence_digest', 64)->nullable();
            $table->timestamp('settlement_verified_at')->nullable();

            // The deterministic bill policy's verdict, denormalised out of the
            // snapshot so the day's unattended outflow can be summed in SQL
            // rather than by decoding JSON. `hasValidSnapshot()` refuses a row
            // where these three disagree with the digest-bound snapshot, so the
            // columns cannot drift from the evidence.
            $table->string('policy_decision');
            $table->string('policy_code');
            $table->boolean('requires_human_approval');

            $table->unsignedBigInteger('amount_base_units');
            $table->unsignedBigInteger('applied_base_units')->default(0);
            $table->unsignedBigInteger('overpayment_base_units')->default(0);
            // 18-decimal native USDC. A string, never an integer and never a
            // float. Null until the chain reports an actual fee; the quoted fee
            // is in the snapshot and is a different promise.
            $table->string('network_fee')->nullable();
            $table->string('payer_address');
            $table->string('recipient_address');
            $table->string('chain');
            $table->string('tx_hash')->nullable();
            $table->boolean('tx_verified_on_chain')->default(false);
            // A rehearsal on a driver that cannot move money. Never evidence of
            // receipts and never eligible for allocation.
            $table->boolean('simulated')->default(false);
            $table->timestamp('allocated_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            // "What has this student settled against this bill, and when".
            $table->index(['student_bill_id', 'consented_at']);
            // The lane's unattended daily outflow, which is scoped by the
            // requires-human-approval flag rather than by the student.
            $table->index(['requires_human_approval', 'consented_at']);
            $table->index(['student_id', 'executed_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('student_bill_payments') && DB::table('student_bill_payments')->exists()) {
            throw new RuntimeException('Student bill consent and settlement evidence exists; use a forward migration.');
        }
        Schema::dropIfExists('student_bill_payments');
    }
};
