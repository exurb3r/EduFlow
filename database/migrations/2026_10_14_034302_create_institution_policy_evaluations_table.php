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
        Schema::create('institution_policy_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_policy_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('source_digest', 64);
            $table->string('policy_digest', 64);
            $table->string('publication_digest', 64);
            $table->string('reason', 1000);
            $table->json('input');
            $table->string('input_digest', 64);
            $table->string('state', 24)->default('queued');
            $table->string('stage', 48)->default('queued');
            $table->unsignedSmallInteger('completed_targets')->default(0);
            $table->unsignedSmallInteger('total_targets')->default(0);
            $table->json('timeline');
            $table->json('result')->nullable();
            $table->string('result_digest', 64)->nullable();
            $table->foreignId('receipt_allocation_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'source_type', 'source_id']);
            $table->index(['organization_id', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('institution_policy_evaluations');
    }
};
