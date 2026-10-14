<?php

declare(strict_types=1);

namespace App\Filament\Finance\Widgets;

use App\DTOs\Money;
use App\Models\FinancePolicyActivation;
use App\Models\MandateOccurrence;
use App\Models\RecurringMandate;
use App\Services\InstallationInstitution;
use App\Services\MandateOutcomeMetrics;
use App\Services\RecordRetrospectiveAgreement;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The bounded automatic lane, reported as it actually stands.
 *
 * An approved recurring mandate is a separate authorization from a per-item
 * payment approval, and this installation has neither: there is no mandate
 * record and no executor. The panel therefore shows the deterministic
 * ceilings that would govern a future mandate while reporting the automatic
 * outcome counts as the zeros they are, rather than as an empty or
 * reassuring-looking zero.
 *
 * Nothing here is linked: recurring mandates are approved through the finance
 * policy workflow, which has no screen yet. Pointing these stats at an
 * unrelated page would be worse than leaving them inert.
 */
class AutonomyLaneWidget extends BaseWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = 4;

    #[\Override]
    public function getHeading(): ?string
    {
        return 'Automatic payment lane';
    }

    #[\Override]
    public function getDescription(): ?string
    {
        return 'Two authorization levels: staff first approve institution policy and any recurring mandate, and only then may eligible occurrences run without a further approval. Neither exists yet, so the initial allowance is zero. A model may propose or explain; it cannot infer that a bill is recurring.';
    }

    #[\Override]
    protected function getStats(): array
    {
        $institution = app(InstallationInstitution::class)->current();

        if ($institution === null) {
            return [Stat::make('Automatic lane', 'Not configured')
                ->description('Configure EDUFLOW_INSTITUTION_ID for a single institution')
                ->color('warning')];
        }

        $activation = FinancePolicyActivation::current($institution->id);
        $policy = $activation?->policyVersion;

        // Read the real records rather than reporting a fixed "None approved".
        // A hardcoded zero is indistinguishable from a lane that has genuinely
        // never run, which is exactly the ambiguity §16.6 flags as dashboard
        // audit debt: "not built yet" and "built, never triggered" are different
        // facts and a supervisor has to be able to tell them apart.
        $mandates = RecurringMandate::query()->where('organization_id', $institution->id)->get();
        // Counted on the mandate's own lifecycle state rather than the review row's
        // decision string, so this agrees with MandateOutcomeMetrics instead of
        // inventing a second way to ask "is this mandate live?".
        $approvedMandates = $mandates->filter(
            fn (RecurringMandate $mandate): bool => $mandate->state === 'approved'
        );
        $occurrenceCounts = MandateOccurrence::query()
            ->where('organization_id', $institution->id)
            ->selectRaw('disposition, COUNT(*) AS aggregate')
            ->groupBy('disposition')
            ->pluck('aggregate', 'disposition');

        $mandateStat = Stat::make('Recurring mandate', $mandates->isEmpty()
            ? 'None approved'
            : sprintf('%d of %d approved', $approvedMandates->count(), $mandates->count()))
            ->description($mandates->isEmpty()
                ? 'Zero allowance. No mandate owner, expiry, due window, frequency or cumulative limit is on record, so no occurrence can run unattended.'
                : sprintf(
                    '%d occurrence(s) evaluated: %d released automatically, %d waiting on a person, %d blocked.',
                    (int) ($occurrenceCounts->get('release', 0) + $occurrenceCounts->get('escalate', 0) + $occurrenceCounts->get('blocked', 0)),
                    (int) $occurrenceCounts->get('release', 0),
                    (int) $occurrenceCounts->get('escalate', 0),
                    (int) $occurrenceCounts->get('blocked', 0),
                ))
            ->descriptionIcon($mandates->isEmpty() ? Heroicon::NoSymbol : Heroicon::CalendarDays)
            ->color($mandates->isEmpty() ? 'gray' : 'info');

        $perPayment = Stat::make('Per-payment ceiling', $policy !== null
            ? Money::formatExact($policy->max_auto_payment_base_units, 'USDC')
            : 'No active policy')
            ->description($policy !== null
                ? sprintf('Policy %s · activated %s. A ceiling, not an authorization.', $policy->version, $activation->created_at->toDateString())
                : 'No policy activation exists. Every amount currently escalates to a human.')
            ->descriptionIcon(Heroicon::Scale)
            ->color($policy !== null ? 'info' : 'warning');

        $dailyStat = Stat::make('Daily disbursement limit', $policy !== null
            ? Money::formatExact($policy->max_daily_disbursement_base_units, 'USDC')
            : 'No active policy')
            ->description($policy !== null
                ? 'Counts settled spending and outstanding reservations together so concurrent proposals cannot reuse the same capacity.'
                : 'No cumulative limit is in force.')
            ->descriptionIcon(Heroicon::CalendarDays)
            ->color($policy !== null ? 'info' : 'warning');

        $feeStat = Stat::make('Fee ceiling', $policy !== null
            ? Money::formatExact($policy->max_fee_base_units, 'USDC')
            : 'No active policy')
            ->description('Bounded separately from principal; fees are preflighted before any submission.')
            ->descriptionIcon(Heroicon::Banknotes)
            ->color($policy !== null ? 'info' : 'warning');

        // Real outcome counts, not a reassuring zero. A lane that escalates
        // everything must be visible as such: an autonomy rate of 100% and one
        // of 0% look identical if you only count what was "handled".
        $metrics = app(MandateOutcomeMetrics::class);
        $decisions = $metrics->decisions($institution);
        $observed = $decisions['release'] + $decisions['escalate'] + $decisions['blocked'];
        $reasons = $metrics->escalationReasons($institution);
        $top = $reasons === [] ? 'nothing escalated' : $this->topEscalation($reasons);

        $outcomes = Stat::make('Decisions vs escalated', $observed === 0 ? 'Nothing yet' : sprintf(
            '%d released · %d escalated · %d blocked',
            $decisions['release'], $decisions['escalate'], $decisions['blocked'],
        ))
            ->description($observed === 0
                ? 'No occurrence has been evaluated yet. Automatic work stays visible here even when it needs no approval notification.'
                : sprintf('Released %s USDC, escalated %s USDC. Most common escalation: %s. Rate released %s of observed occurrences.',
                    Money::formatExact($decisions['release_base_units'], 'USDC'),
                    Money::formatExact($decisions['escalate_base_units'], 'USDC'),
                    $top,
                    number_format($metrics->autonomyRate($decisions) * 100, 1).'%',
                ))
            ->descriptionIcon(Heroicon::ChartBar)
            ->color($decisions['release'] > 0 ? 'info' : 'gray');

        // Labelled "feedback", never "agreement rate", and the caveat travels
        // with the number. §18.6 is explicit that this is not approval.
        $rate = app(RecordRetrospectiveAgreement::class)->rate($institution);

        $agreement = Stat::make('Supervisor feedback (retrospective)', $rate['reviewed'] === 0
            ? 'Not reviewed yet'
            : sprintf('%d of %d would agree', $rate['agreed'], $rate['reviewed']))
            ->description($rate['reviewed'] === 0
                ? 'Released occurrences are sampled and shown to the responsible supervisor afterwards. No sample has been reviewed yet.'
                : sprintf('%d released, %d not yet reviewed. Retrospective feedback only — it is not per-item approval and not proof of correctness.',
                    $rate['released_total'], $rate['unreviewed']))
            ->descriptionIcon(Heroicon::ChatBubbleLeftRight)
            ->color('gray');

        return [$mandateStat, $perPayment, $dailyStat, $feeStat, $outcomes, $agreement];
    }

    /**
     * @param  array<string, int>  $reasons
     */
    private function topEscalation(array $reasons): string
    {
        $name = (string) array_key_first($reasons);

        return str_replace('_', ' ', $name);
    }
}
