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
        Schema::create('institution_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('predecessor_id')->nullable()->constrained('institution_policy_versions')->restrictOnDelete();
            $table->foreignId('finance_policy_version_id')->constrained()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('title', 160);
            $table->json('content');
            $table->string('content_digest', 64);
            $table->timestamps();
            $table->index(['organization_id', 'id']);
        });
        Schema::create('institution_policy_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_policy_version_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('finance_policy_activation_id')->constrained()->restrictOnDelete();
            $table->foreignId('previous_publication_id')->nullable()->constrained('institution_policy_publications')->restrictOnDelete();
            $table->string('policy_digest', 64);
            $table->string('reason', 1000);
            $table->string('publication_digest', 64);
            $table->timestamps();
        });
        Schema::create('institution_policy_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('institution_policy_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('kind', 24);
            $table->string('state', 24)->default('queued');
            $table->json('input');
            $table->string('policy_digest', 64);
            $table->string('input_digest', 64);
            $table->json('result')->nullable();
            $table->string('result_digest', 64)->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'state']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('institution_policy_assessments');
        Schema::dropIfExists('institution_policy_publications');
        Schema::dropIfExists('institution_policy_versions');
    }
};
