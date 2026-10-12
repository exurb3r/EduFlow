<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MandateOccurrence;
use App\Services\InstallationInstitution;
use App\Services\MandateOccurrenceScanner;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * One unattended tick of the standing-mandate lane.
 *
 * Sessionless by construction: no browser, no chat, no model call, no human,
 * and no fabricated actor handed to a staff action. It classifies obligations
 * and records what the deterministic evaluator concluded.
 *
 * It submits nothing. A `release` means this occurrence may skip one approval;
 * turning that into a payment needs the separately reviewed service authority,
 * which does not exist yet — so this command deliberately stops short of it
 * rather than reaching for an `Admin` user to satisfy a policy check.
 */
class DispatchMandateOccurrences extends Command
{
    protected $signature = 'eduflow:dispatch-mandate-occurrences';

    protected $description = 'Classify due recurring obligations against approved standing mandates; submits nothing';

    public function handle(MandateOccurrenceScanner $scanner, InstallationInstitution $institutions): int
    {
        try {
            $institution = $institutions->require();
        } catch (ValidationException $exception) {
            $this->warn($exception->getMessage());

            return self::FAILURE;
        }

        // Off by default, and off means nothing at all happens: no read, no
        // record, no occurrence. The lane cannot be switched on by a schedule
        // that was left in place.
        if (! config('eduflow.mandate.runtime_enabled', false)) {
            $this->info('Mandate lane is disabled. Nothing was read or recorded.');

            return self::SUCCESS;
        }

        $summary = $scanner->scan($institution);

        $this->info(sprintf(
            'Mandates in scope %d; occurrences released %d, escalated %d, blocked %d; already recorded %d.',
            $summary['scanned'], $summary['release'], $summary['escalate'], $summary['blocked'], $summary['skipped'],
        ));

        // Automatic work stays visible even when no notification is needed
        // (§18.6), so anything needing a human is named on the console.
        foreach (MandateOccurrence::query()->where('organization_id', $institution->id)
            ->whereIn('disposition', ['escalate', 'blocked'])->latest('id')->limit(10)->get() as $occurrence) {
            $this->warn(sprintf('  #%d %s — %s', $occurrence->id, $occurrence->disposition, (string) $occurrence->reason));
        }

        return self::SUCCESS;
    }
}
