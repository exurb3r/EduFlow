<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            // Where assistance payments are actually sent. The agent used to
            // invent an address from a hash of the user id, which the Circle
            // CLI rejected as an invalid destination.
            $table->string('payout_address', 42)->nullable()->after('attendance_rate');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->dropColumn('payout_address');
        });
    }
};
