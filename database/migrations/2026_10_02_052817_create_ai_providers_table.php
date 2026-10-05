<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();

            // Shown in the admin list so several endpoints can be told apart.
            $table->string('name');

            // Which laravel/ai driver to build: openai, anthropic, groq,
            // ollama, openai-compatible, and so on.
            $table->string('driver')->default('openai-compatible');

            // Required for any openai-compatible endpoint (Ollama, LM Studio,
            // vLLM, LiteLLM, Together, a corporate gateway).
            $table->string('base_url')->nullable();

            // The text model to default to for this provider.
            $table->string('model')->nullable();

            // Encrypted at rest by the model's `encrypted` cast. Never store a
            // plaintext key here.
            $table->text('api_key')->nullable();

            // Extra headers some compatible gateways require (e.g. X-Tenant-Id).
            $table->json('headers')->nullable();

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            // Only one default is meaningful, so MySQL/Postgres/SQLite all get
            // the same guarantee from application logic rather than a partial
            // unique index that SQLite would ignore.
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
