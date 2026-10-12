<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('student_tuition_payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('quoted_for')->constrained('users')->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('tuition_account_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_wallet_id')->constrained()->restrictOnDelete();
            $table->json('snapshot');
            $table->char('snapshot_digest', 64);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('student_tuition_payment_intents') && DB::table('student_tuition_payment_intents')->exists()) {
            throw new RuntimeException('Tuition consent evidence exists; use a forward migration.');
        }
        Schema::dropIfExists('student_tuition_payment_intents');
    }
};
