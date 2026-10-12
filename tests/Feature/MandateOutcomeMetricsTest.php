<?php

declare(strict_types=1);

use App\Models\MandateOccurrence;
use App\Models\MandateRetrospectiveReview;
use App\Services\MandateOccurrenceScanner;
use App\Services\MandateOutcomeMetrics;
use App\Services\RecordRetrospectiveAgreement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What the lane decided, and how often a supervisor would have agreed.
 *
 * The property under test throughout is that these two never collapse into one
 * flattering number. `decisions` is throughput. `rate` is retrospective
 * feedback over a sample, and it is not an authorization metric -- which the
 * model enforces structurally, not just by naming.
 */

/**
 * An approved, funded mandate with bills in its closed set.
 *
 * @param  array<int, string>  $amounts
 * @return array<string, mixed>
 */
function metricsContext(array $c, array $amounts = ['4.000000']): array
{
    foreach ($amounts as $amount) {
        scannerBills($c, $amount);
    }

    $ids = DB::table('invoice_versions')->where('organization_id', $c['institution']->id)->pluck('id')->all();
    approveAndFund($c, '5000000', $ids);
    config(['eduflow.mandate.runtime_enabled' => true]);

    return $c;
}

/** Run one tick and return the occurrence it recorded, if any. */
function scanOnce(array $c): ?MandateOccurrence
{
    app(MandateOccurrenceScanner::class)->scan($c['institution']);

    return MandateOccurrence::query()->latest('id')->first();
}

/** @param array<string, mixed> $c */
function metricsReleasedOccurrence(array $c, string $amount = '4.000000'): MandateOccurrence
{
    metricsContext($c, [$amount]);

    return scanOnce($c) ?? throw new RuntimeException('no occurrence recorded');
}

test('decisions counts each disposition separately rather than as one total', function (): void {
    // One inside the ceiling, one above it.
    $c = metricsContext(mandateContext(), ['4.000000', '9.000000']);
    scanOnce($c);

    $metrics = app(MandateOutcomeMetrics::class);
    $decisions = $metrics->decisions($c['institution']);

    expect($decisions['release'])->toBe(1)
        ->and($decisions['escalate'])->toBe(1)
        ->and($decisions['blocked'])->toBe(0)
        // Exact base units, never a float figure for a money total.
        ->and($decisions['release_base_units'])->toBe('4000000')
        ->and($decisions['escalate_base_units'])->toBe('9000000');
});

test('autonomy rate is a throughput figure, not an accuracy figure', function (): void {
    $metrics = app(MandateOutcomeMetrics::class);

    expect($metrics->autonomyRate(['release' => 3, 'escalate' => 1, 'blocked' => 0]))->toBe(0.75)
        // A lane that escalates everything is 0%, not "100% handled".
        ->and($metrics->autonomyRate(['release' => 0, 'escalate' => 5, 'blocked' => 2]))->toBe(0.0)
        ->and($metrics->autonomyRate(['release' => 0, 'escalate' => 0, 'blocked' => 0]))->toBe(0.0);
});

test('escalations are grouped by the gate that held, not just counted', function (): void {
    $c = metricsContext(mandateContext(), ['9.000000', '9.000000']);
    scanOnce($c);

    $reasons = app(MandateOutcomeMetrics::class)->escalationReasons($c['institution']);

    // "12 escalated" teaches nothing; "12 escalated, 9 for price change" does.
    expect($reasons)->toHaveKey('amount_within_ceiling')
        ->and($reasons['amount_within_ceiling'])->toBe(2);
});

test('an unreviewed lane reports no agreement rate rather than zero', function (): void {
    $c = mandateContext();
    metricsReleasedOccurrence($c);

    $rate = app(RecordRetrospectiveAgreement::class)->rate($c['institution']);

    // Zero would read as "the admin disagreed with every release". The truth is
    // that nobody has looked yet, and those are different claims.
    expect($rate['agreement_rate'])->toBeNull()
        ->and($rate['reviewed'])->toBe(0)
        ->and($rate['released_total'])->toBe(1)
        ->and($rate['unreviewed'])->toBe(1)
        ->and($rate['is_sample'])->toBeTrue();
});

test('a supervisor may record retrospective feedback on a released occurrence', function (): void {
    $c = mandateContext();
    $occurrence = metricsReleasedOccurrence($c);

    $review = app(RecordRetrospectiveAgreement::class)->handle($c['reviewer'], $occurrence, 'agreed', 'Would have approved this.');

    expect($review->hasValidEvidence($occurrence))->toBeTrue()
        ->and($review->verdict)->toBe('agreed')
        ->and($review->occurrence_digest)->toBe($occurrence->occurrence_digest);

    $rate = app(RecordRetrospectiveAgreement::class)->rate($c['institution']);

    expect($rate['agreement_rate'])->toBe(1.0)
        ->and($rate['reviewed'])->toBe(1)
        ->and($rate['unreviewed'])->toBe(0);
});

test('retrospective feedback is never an approval', function (): void {
    $c = mandateContext();
    $occurrence = metricsReleasedOccurrence($c);

    $review = app(RecordRetrospectiveAgreement::class)->handle($c['reviewer'], $occurrence, 'agreed', 'Fine.');

    // Structurally, not just by naming: nothing about this record can be read
    // as ratifying or re-authorizing the payment.
    expect($review->isApproval())->toBeFalse()
        ->and($review->evidence()['is_approval'])->toBeFalse()
        ->and($review->evidence()['is_retrospective_feedback'])->toBeTrue()
        ->and($review->evidence()['payment_approved'])->toBeFalse()
        ->and($review->evidence()['can_execute'])->toBeFalse();
});

test('a disagreement is recorded honestly and lowers the rate', function (): void {
    $c = metricsContext(mandateContext(), ['4.000000', '4.000000']);
    scanOnce($c);

    // Both bills are in the closed set, so one tick releases both; the
    // supervisor agrees with one and not the other.
    $releases = MandateOccurrence::query()->where('disposition', 'release')->orderBy('id')->get();
    expect($releases)->toHaveCount(2);

    app(RecordRetrospectiveAgreement::class)->handle($c['reviewer'], $releases[0], 'agreed', 'Would have approved.');
    app(RecordRetrospectiveAgreement::class)->handle($c['reviewer'], $releases[1], 'would_have_escalated', 'Price looked high.');

    $rate = app(RecordRetrospectiveAgreement::class)->rate($c['institution']);

    expect($rate['reviewed'])->toBe(2)
        ->and($rate['agreed'])->toBe(1)
        ->and($rate['escalated_would_be'])->toBe(1)
        ->and($rate['agreement_rate'])->toBe(0.5);
});

test('one occurrence cannot be reviewed twice, so a rate cannot be inflated', function (): void {
    $c = mandateContext();
    $occurrence = metricsReleasedOccurrence($c);
    app(RecordRetrospectiveAgreement::class)->handle($c['reviewer'], $occurrence, 'agreed', 'First look.');

    expect(fn () => app(RecordRetrospectiveAgreement::class)->handle($c['reviewer'], $occurrence, 'agreed', 'Second look.'))
        ->toThrow(ValidationException::class, 'already been reviewed retrospectively')
        ->and(MandateRetrospectiveReview::query()->count())->toBe(1);
});

test('a retrospective review cannot be edited after the fact', function (): void {
    $c = mandateContext();
    $occurrence = metricsReleasedOccurrence($c);
    $review = app(RecordRetrospectiveAgreement::class)->handle($c['reviewer'], $occurrence, 'would_have_declined', 'Wrong bill.');

    $review->verdict = 'agreed';

    expect(fn () => $review->save())->toThrow(LogicException::class, 'append-only');
});

test('an occurrence that was never released cannot be reviewed retrospectively', function (): void {
    $c = metricsContext(mandateContext(), ['9.000000']);
    scanOnce($c);

    $escalated = MandateOccurrence::query()->where('disposition', 'escalate')->firstOrFail();

    // There was no automatic decision to have an opinion about: the bill went
    // to a human precisely because the lane would not decide it.
    expect(fn () => app(RecordRetrospectiveAgreement::class)->handle($c['reviewer'], $escalated, 'agreed', 'Fine.'))
        ->toThrow(ValidationException::class, 'Only a validly released occurrence');
});

test('the sample offers only unreviewed released occurrences', function (): void {
    $c = metricsContext(mandateContext(), ['4.000000', '4.000000']);
    scanOnce($c);

    $releases = MandateOccurrence::query()->where('disposition', 'release')->orderBy('id')->get();
    app(RecordRetrospectiveAgreement::class)->handle($c['reviewer'], $releases[0], 'agreed', 'Done.');
    $second = $releases[1];

    $sample = app(RecordRetrospectiveAgreement::class)->sample($c['institution']);

    expect($sample)->toHaveCount(1)
        ->and($sample[0]->id)->toBe($second->id);
});
