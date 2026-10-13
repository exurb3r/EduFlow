<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Advisory\AdvisoryMandateRanking;
use App\Models\Invoice;
use App\Models\MandateOccurrence;
use App\Services\Advisory\MandateCandidateRanker;

/**
 * What the standing-mandate lane decided, for a supervisor to read.
 *
 * This is a **read model**. It creates nothing, authorizes nothing and releases
 * nothing, and it deliberately exposes no action: an occurrence that evaluated
 * to `escalate` still needs a reservation and a payment review on
 * {@see Payment Reviews} before any money is authorized, and a
 * reviewer must pass the same MFA-backed gate they would for any other payment.
 *
 * The reason it exists is a gap rather than a feature. `MandateOccurrenceScanner`
 * writes a disposition and a full set of checks for every due occurrence, and
 * until now nothing rendered them — so a recurring bill evaluating to `escalate`
 * was recorded with a precise reason and then invisible, and the advisory ranking
 * that tells a supervisor *which* bills deserve a mandate was never shown either.
 *
 * Two things are kept deliberately apart in the output, because merging them is
 * how an advisory opinion starts reading as a decision:
 *
 *  - `checks` are the deterministic `MandateReleaseEvaluator` result. These decide.
 *  - `advisory` is the model's read. It explains and ranks; it decides nothing.
 */
final readonly class MandateSupervision
{
    /**
     * Occurrences a supervisor may want to look at, newest due date first.
     *
     * Bounded per call because this feeds a page, not an export: a lane with
     * years of daily occurrences must not render every row just because someone
     * opened a review screen.
     *
     * @return array{occurrences: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function occurrenceOverview(int $limit = 25): array
    {
        $institution = app(InstallationInstitution::class)->current();
        $occurrences = $institution === null
            ? collect()
            : MandateOccurrence::query()
                ->where('organization_id', $institution->id)
                ->orderByDesc('due_at')
                ->limit($limit)
                ->get();

        $counts = [];
        foreach (['release', 'escalate', 'blocked'] as $disposition) {
            $counts[$disposition] = $institution === null ? 0 : MandateOccurrence::query()
                ->where('organization_id', $institution->id)
                ->where('disposition', $disposition)
                ->count();
        }

        return [
            'occurrences' => $occurrences
                ->map(fn (MandateOccurrence $occurrence): array => $this->occurrenceData($occurrence))
                ->all(),
            'counts' => $counts,
        ];
    }

    /**
     * One occurrence, in the words a supervisor needs.
     *
     * The failed check is named in full rather than summarised as "needs
     * review", because the difference between "outside its mandate ceiling" and
     * "destination changed" decides what the reviewer has to go and look at.
     *
     * @return array<string, mixed>
     */
    private function occurrenceData(MandateOccurrence $occurrence): array
    {
        $checks = is_array($occurrence->checks) ? $occurrence->checks : [];
        $failed = [];
        $passed = [];

        foreach ($checks as $name => $check) {
            // `evaluated_at` is metadata rather than a gate, so it is reported
            // apart instead of being counted as a check that held or failed.
            if (! is_array($check)) {
                continue;
            }
            if (! array_key_exists('passed', $check)) {
                continue;
            }
            if (($check['passed'] ?? false) === true) {
                $passed[] = $name;

                continue;
            }

            $failed[] = [
                'check' => $name,
                'severity' => is_string($check['severity'] ?? null) ? $check['severity'] : 'escalate',
                'reason' => is_string($check['reason'] ?? null) ? $check['reason'] : $name,
            ];
        }

        return [
            'id' => $occurrence->id,
            'disposition' => $occurrence->disposition,
            'amount_base_units' => (string) $occurrence->amount_base_units,
            'due_at' => $occurrence->due_at->toIso8601String(),
            'chain' => $occurrence->chain,
            'obligation_reference' => $occurrence->mandate?->obligation_reference,
            'vendor_name' => $occurrence->mandate?->vendor->name,
            // The evaluator's own one-line reason, verbatim. Never rewritten.
            'reason' => $occurrence->reason,
            'failed_checks' => $failed,
            'passed_check_count' => count($passed),
            // An occurrence is only trustworthy while its evidence digest still
            // re-derives; a row that fails is shown as suspect rather than
            // quietly presented as a decision.
            'evidence_valid' => $occurrence->hasValidEvidence(),
        ];
    }

    /**
     * The model's read of which open bills might deserve a standing mandate.
     *
     * Shown because it genuinely speeds up a reviewer's queue, and hidden behind
     * the fact that `advisory_only` is always true. A provider failure returns
     * null, so this panel says "unavailable" rather than implying the AI
     * considered the bills and found nothing.
     *
     * @return array<string, mixed>|null
     */
    public function advisoryRanking(): ?array
    {
        $institution = app(InstallationInstitution::class)->current();
        if ($institution === null) {
            return null;
        }

        $ranking = app(MandateCandidateRanker::class)->rank(
            Invoice::query()
                ->where('organization_id', $institution->id)
                ->where('status', '!=', 'paid')
                ->orderBy('due_date')
                ->limit(25)
                ->get()
                ->map(fn (Invoice $invoice): array => [
                    'id' => $invoice->id,
                    'vendor' => $invoice->vendor->name,
                    'reference' => $invoice->reference,
                    'category' => $invoice->category,
                    // The legacy column is a float; the advisory ranker reads a
                    // display string, and this is presentation only. Nothing
                    // financial is decided from it.
                    'amount' => number_format((float) $invoice->amount, 2, '.', ''),
                    'history_look' => $invoice->status === 'paid'
                        ? 'already paid'
                        : 'no prior payment recorded',
                ])
                ->all()
        );

        if (! $ranking instanceof AdvisoryMandateRanking) {
            return null;
        }

        return [
            'advisory_only' => $ranking->advisoryOnly,
            'narrative' => $ranking->narrative,
            'candidates' => $ranking->ranked(),
        ];
    }
}
