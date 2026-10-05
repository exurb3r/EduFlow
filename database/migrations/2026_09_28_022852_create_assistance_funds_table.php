<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistance_funds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('Emergency Assistance Fund');
            // USDC base units (6 decimals): 2,400 USDC = 2_400_000000
            $table->bigInteger('balance_base_units')->default(2400000000);
            $table->bigInteger('reserve_threshold_base_units')->default(5000000000);
            $table->bigInteger('daily_budget_base_units')->default(1000000000);
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistance_funds');
    }
};
