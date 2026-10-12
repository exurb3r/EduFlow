<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('student_tuition_payment_attempts', function (Blueprint $table): void {
            $table->foreignId('student_tuition_payment_intent_id')->nullable()->constrained()->restrictOnDelete()->unique();
            $table->json('consent_snapshot')->nullable();
            $table->char('consent_digest', 64)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('submission_started_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->json('settlement_evidence')->nullable();
            $table->char('settlement_evidence_digest', 64)->nullable();
            $table->string('actual_network_fee')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('investigation_owner_id')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::table('student_tuition_payments', function (Blueprint $table): void {
            $table->foreignId('student_tuition_payment_attempt_id')->nullable()->constrained()->restrictOnDelete()->unique();
            $table->char('movement_key', 64)->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new RuntimeException('Tuition recovery evidence is append-only; use a forward migration.');
    }
};
