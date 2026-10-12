<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MandateOccurrence;
use App\Models\Organization;
use App\Models\RecurringMandate;
use Illuminate\Support\Carbon;

/**
 * Counts for the autonomous lane, kept deliberately separate.
 *
 * §14.6 and §18.6 require that these never be collapsed into one flattering
 * number, so this service reports them as distinct measures with distinct
 * names:
 *
 * - **decisions vs escalated** — what the deterministic evaluator concluded for
 *   each obligation it looked at. `release` means every gate passed; `escalate`
 *   means valid but outside the mandate; `blocked` means it could not be
 *   released at all. Counting these together as "handled" would hide a lane that
 *   escalates everything and is therefore autonomous in name only.
 * - **retrospective agreement** — a *sample* of automatically released
 *   occurrences shown to the responsible supervisor afterwards, who records
 *   whether they would have decided the same thing.
 *
 * The second is **feedback, not approval**, and there is no per-item approval
 * for an automatic release because there is no per-item approver. It is
 * reported under its own name and must never be presented as an authorization
 * metric, a correctness measure, or evidence that a payment was right.
 *
 * Note also what is deliberately absent: there is no "approval-as-is rate" for
 * this lane. That metric is defined over proposals a human actually reviewed,
 * and automatic releases by definition had none, so computing one here would
 * invent a denominator.
 */
final readonly class MandateOutcomeMetrics
{
    /**
     * What the evaluator concluded, for a window.
     *
     * @return array{
     *     release:int, escalate:int, blocked:int, skipped:int,
     *     release_base_units:string, escalate_base_units:string, blocked_base_units:string,
     *     mandates_in_scope:int, live_mandates:int
     * }
     */
    public function decisions(Organization $institution, ?Carbon $since = null, ?Carbon $until = null): array
    {
        $since ??= Carbon::now()->subDays(30);
        $until ??= Carbon::now();

        $occurrences = MandateOccurrence::query()
            ->where('organization_id', $institution->id)
            ->whereBetween('created_at', [$since, $until])
            ->get(['disposition', 'amount_base_units']);

        $summary = ['release' => 0, 'escalate' => 0, 'blocked' => 0];
        $amounts = ['release' => 0, 'escalate' => 0, 'blocked' => 0];

        foreach ($occurrences as $occurrence) {
            $disposition = $occurrence->disposition;

            if (! array_key_exists($disposition, $summary)) {
                continue;
            }

            $summary[$disposition]++;
            $amounts[$disposition] += $occurrence->amount_base_units;
        }

        return [
            'release' => $summary['release'],
            'escalate' => $summary['escalate'],
            'blocked' => $summary['blocked'],
            'skipped' => 0,
            'release_base_units' => (string) $amounts['release'],
            'escalate_base_units' => (string) $amounts['escalate'],
            'blocked_base_units' => (string) $amounts['blocked'],
            'mandates_in_scope' => RecurringMandate::query()->where('organization_id', $institution->id)->count(),
            'live_mandates' => RecurringMandate::query()->where('organization_id', $institution->id)
                ->where('state', 'approved')->count(),
        ];
    }

    /**
     * The share of obligations that ran without a human, stated plainly.
     *
     * This is a throughput measure, not an accuracy one: a lane can have a
     * high autonomy rate because it is correct, or because its mandates are
     * written so tightly that almost nothing ever qualifies. Both look
     * identical here, which is why it must be read beside the escalation
     * reasons rather than on its own.
     */
    public function autonomyRate(array $decisions): float
    {
        $total = $decisions['release'] + $decisions['escalate'] + $decisions['blocked'];

        return $total === 0 ? 0.0 : round($decisions['release'] / $total, 4);
    }

    /**
     * Why obligations were escalated, grouped by the gate that held.
     *
     * An operator reading "12 escalated" learns nothing; reading "12 escalated,
     * 9 for price change, 2 for changed destination, 1 for daily cap" is
     * actionable, and is the difference between a metric and a dashboard.
     *
     * @return array<string, int>
     */
    public function escalationReasons(Organization $institution, ?Carbon $since = null): array
    {
        $since ??= Carbon::now()->subDays(30);
        $reasons = [];

        MandateOccurrence::query()
            ->where('organization_id', $institution->id)
            ->where('disposition', 'escalate')
            ->where('created_at', '>=', $since)
            ->get(['checks'])
            ->each(function (MandateOccurrence $occurrence) use (&$reasons): void {
                $checks = $occurrence->checks ?? [];

                foreach ($checks as $name => $check) {
                    if (! is_array($check)) {
                        continue;
                    }
                    if (($check['passed'] ?? true) === true) {
                        continue;
                    }
                    if (($check['severity'] ?? null) !== 'escalate') {
                        continue;
                    }
                    $key = $name;

                    $reasons[$key] = ($reasons[$key] ?? 0) + 1;
                }
            });

        arsort($reasons);

        return $reasons;
    }
}
