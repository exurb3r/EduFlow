<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistance_policy_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('version')->default('v1');
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            // 100 USDC auto-limit = 100_000000 base units
            $table->bigInteger('auto_limit_base_units')->default(100000000);
            // Semester cap per student, e.g. 500 USDC
            $table->bigInteger('semester_cap_base_units')->default(500000000);
            $table->decimal('min_attendance_rate', 5, 2)->default(85.00);
            $table->string('required_enrollment_status')->default('enrolled');
            $table->string('required_academic_status')->default('qualified');
            $table->boolean('is_active')->default(true);
            $table->json('rules')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistance_policy_versions');
    }
};
