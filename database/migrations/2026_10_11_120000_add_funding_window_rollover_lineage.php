<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a funding window name the window it replaces.
 *
 * A window expires in 15 minutes and cannot be reopened, so an unattended lane
 * needs an explicit, reviewed rollover to keep paying bills. That rollover
 * needs a lineage: which expired window this one continues, and proof that it
 * did. The link is unique, so one predecessor can be rolled over exactly once
 * and a successor cannot be minted twice from the same window in a race.
 *
 * Capacity is not reset by a rollover. The hold chain is institution-wide and
 * cumulative, and `ReservationCapacity` verifies each hold against the window
 * it was actually taken under, so a successor re-observes cash without ever
 * re-granting room that is already reserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funding_windows', function (Blueprint $table): void {
            $table->foreignId('supersedes_funding_window_id')
                ->nullable()
                ->unique()
                ->constrained('funding_windows')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('funding_windows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supersedes_funding_window_id');
        });
    }
};
