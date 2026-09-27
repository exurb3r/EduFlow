<?php

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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('school');
            $table->string('currency')->default('USDC');
            $table->decimal('minimum_reserve', 15, 2)->default(10000.00);
            $table->decimal('max_auto_payment', 15, 2)->default(1000.00);
            $table->decimal('max_daily_disbursement', 15, 2)->default(5000.00);
            $table->decimal('human_approval_threshold', 15, 2)->default(1000.00);
            $table->timestamps();
        });

        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('circle');
            $table->string('network')->default('arc');
            $table->string('address')->index();
            $table->decimal('balance', 15, 2)->default(0.00);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->index();
            $table->decimal('allocated_amount', 15, 2);
            $table->decimal('spent_amount', 15, 2)->default(0.00);
            $table->decimal('remaining_amount', 15, 2);
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('category')->default('general');
            $table->string('email')->nullable();
            $table->string('wallet_address')->index();
            $table->string('status')->default('verified'); // verified, pending, suspended
            $table->string('risk_level')->default('low'); // low, medium, high
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('budget_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->decimal('amount', 15, 2);
            $table->date('due_date');
            $table->string('category')->default('operations');
            $table->string('status')->default('pending'); // pending, auto_approved, escalated, paid, rejected, held
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // vendor_payment, student_assistance, tuition_revenue, refund
            $table->string('recipient_address')->index();
            $table->decimal('amount', 15, 2);
            $table->string('currency')->default('USDC');
            $table->string('status')->default('pending'); // pending, confirmed, failed
            $table->string('provider_tx_hash')->nullable()->index();
            $table->string('network')->default('arc');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('action_type'); // pay_vendor, student_assistance, hold_payment, forecast_alert
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->json('input_snapshot');
            $table->text('reasoning_summary');
            $table->string('policy_checked');
            $table->string('decision'); // auto_approve, partial_approval, hold, escalate, reject
            $table->decimal('requested_amount', 15, 2);
            $table->decimal('approved_amount', 15, 2)->default(0.00);
            $table->boolean('requires_approval')->default(false);
            $table->string('status')->default('pending'); // pending, executed, escalated, rejected, held
            $table->timestamps();
        });

        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_decision_id')->constrained()->cascadeOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->text('comment')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('agent_decisions');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('organizations');
    }
};
