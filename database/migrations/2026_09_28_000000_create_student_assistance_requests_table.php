<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('wallet_address')->nullable()->after('password');
        });

        Schema::create('student_assistance_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('request_type')->default('emergency_assistance'); // emergency_assistance, tuition_assistance, refund
            $table->text('reason');
            $table->decimal('requested_amount', 15, 2);
            $table->decimal('approved_amount', 15, 2)->default(0.00);
            $table->string('status')->default('auto_approved'); // auto_approved, escalated, approved_by_human, rejected, paid
            $table->string('reference_number')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_assistance_requests');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('wallet_address');
        });
    }
};
