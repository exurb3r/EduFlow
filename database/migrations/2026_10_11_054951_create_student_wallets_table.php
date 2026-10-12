<?php

declare(strict_types=1);

use App\Models\Student;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A wallet generated for a student through the Circle CLI.
 *
 * The address itself lives on students.payout_address, which stays the single
 * destination the disbursement path pays. This table is the provenance record:
 * which provider minted it, on which chain, and when. One row per student.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('address');
            $table->string('provider')->default('circle');
            $table->string('network')->default('arc');
            $table->string('chain');
            $table->string('status')->default('active');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique('student_id');
            $table->unique('address');
            $table->index('chain');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_wallets');
    }
};
