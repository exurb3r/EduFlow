<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('base_code')->default('USDC');
            $table->string('quote_code')->index();
            $table->bigInteger('units_per_usdc');
            $table->string('provider')->default('fallback');
            $table->timestamp('quoted_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_fallback')->default(true);
            $table->timestamps();
            $table->unique(['base_code', 'quote_code', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');
    }
};
