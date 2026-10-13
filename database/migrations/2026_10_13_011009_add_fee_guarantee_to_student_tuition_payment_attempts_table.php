<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which fee bound actually protected a submission.
 *
 * A signer-enforced ceiling and a balance-bounded one are different promises,
 * and a reviewer reading this row later has to be able to tell them apart.
 * Without the column the only honest answer would be "some bound applied",
 * which is exactly the ambiguity that turns a bounded payment into an assumed
 * one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_tuition_payment_attempts', function (Blueprint $table): void {
            $table->string('fee_guarantee', 32)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Fee-guarantee evidence is append-only; use a forward migration.');
    }
};
