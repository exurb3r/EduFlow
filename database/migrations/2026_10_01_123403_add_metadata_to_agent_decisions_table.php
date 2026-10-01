<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_decisions', function (Blueprint $table): void {
            // Where advisory model commentary lives, namespaced under an
            // `advisory` key so it can never overwrite an authoritative field
            // such as approved_amount or policy_checked. Matches the metadata
            // column already present on invoices and transactions.
            $table->json('metadata')->nullable()->after('input_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('agent_decisions', function (Blueprint $table): void {
            $table->dropColumn('metadata');
        });
    }
};
