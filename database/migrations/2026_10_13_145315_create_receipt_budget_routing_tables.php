<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_budget_routes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_snapshot_id')->constrained()->restrictOnDelete();
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->string('source_stream', 64);
            $table->string('currency', 8);
            $table->timestamp('receipts_from');
            $table->timestamp('valid_until');
            $table->json('snapshot');
            $table->string('snapshot_digest', 64);
            $table->timestamps();
            $table->index(['organization_id', 'source_stream', 'currency'], 'receipt_route_source_index');
        });
        Schema::create('receipt_budget_route_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('receipt_budget_route_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->string('route_digest', 64);
            $table->string('reason', 1000);
            $table->string('approval_digest', 64);
            $table->timestamps();
        });
        Schema::create('receipt_plan_triggers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('receipt_budget_route_approval_id')->constrained()->restrictOnDelete();
            $table->foreignId('collection_batch_review_id')->unique()->constrained()->restrictOnDelete();
            $table->string('review_digest', 64);
            $table->string('approval_digest', 64);
            $table->foreignId('predecessor_snapshot_id')->nullable()->constrained('budget_snapshots')->restrictOnDelete();
            $table->foreignId('budget_snapshot_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('state', 24)->default('queued');
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['receipt_budget_route_approval_id', 'predecessor_snapshot_id'], 'receipt_plan_successor_unique');
            $table->index(['organization_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_plan_triggers');
        Schema::dropIfExists('receipt_budget_route_approvals');
        Schema::dropIfExists('receipt_budget_routes');
    }
};
