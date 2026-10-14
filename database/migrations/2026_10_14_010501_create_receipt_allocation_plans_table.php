<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_allocation_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('budget_snapshot_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_policy_version_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('currency', 10);
            $table->string('snapshot_digest', 64);
            $table->string('policy_digest', 64);
            $table->string('publication_digest', 64);
            $table->unsignedBigInteger('proposed_minor_units')->default(0);
            $table->unsignedBigInteger('unallocated_minor_units')->default(0);
            $table->json('plan');
            $table->string('plan_digest', 64);
            $table->timestamps();
            $table->index(['organization_id', 'budget_snapshot_id']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_allocation_plans');
    }
};
