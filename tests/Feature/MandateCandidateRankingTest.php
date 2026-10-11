<?php

declare(strict_types=1);

use App\Ai\Advisory\AdvisoryMandateRanking;
use App\Services\Advisory\MandateCandidateRanker;
use App\Settings\AiSettings;
use Laravel\Ai\Ai;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

/**
 * The mandate-candidate ranking lane.
 *
 * Jev is a classification model, and classification is the only kind of question
 * this product is willing to put to a model on the payment path. What these
 * tests protect is not the model's accuracy, which is nobody's problem, but the
 * three properties that keep it advisory: it cannot produce authority, it never
 * sees the decision inputs, and it fails closed rather than open.
 */
function candidateBills(): array
{
    return [
        ['id' => 1, 'vendor' => 'Campus FiberNet Telecom', 'reference' => 'INV-FIBER-30', 'category' => 'utilities', 'amount' => '30.00', 'history_look' => 'six identical monthly invoices'],
        ['id' => 2, 'vendor' => 'Apex STEM Lab Supplies', 'reference' => 'INV-LAB-90', 'category' => 'equipment', 'amount' => '90.00', 'history_look' => 'no prior invoices'],
    ];
}

function withAiEnabled(): AiSettings
{
    $settings = app(AiSettings::class);
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    app()->instance(AiSettings::class, $settings);

    return $settings;
}

test('the ranking carries no authority to spend anything', function (): void {
    // The type is the boundary. If a future field could express an amount, a
    // recipient or a verdict, a caller would eventually promote it.
    $ranking = new AdvisoryMandateRanking(
        candidates: [['bill_id' => 1, 'recurring_likelihood' => 0.9, 'stable_amount' => true, 'rationale' => 'repeats']],
    );

    $payload = $ranking->toArray();

    expect($payload['advisory_only'])->toBeTrue()
        ->and($payload['decides_payment'])->toBeFalse()
        ->and($payload)->not->toHaveKey('amount_base_units')
        ->and($payload)->not->toHaveKey('recipient_address')
        ->and($payload)->not->toHaveKey('ceiling')
        ->and($payload)->not->toHaveKey('verdict')
        ->and(array_keys($payload['candidates'][0]))->toBe(['bill_id', 'recurring_likelihood', 'stable_amount', 'rationale']);
});

test('an unstable amount is demoted, not hidden', function (): void {
    // A bill that varies is the one where unattended payment is most dangerous.
    // Ranking it lower is a warning; ranking it nowhere would be a silence.
    $ranking = new AdvisoryMandateRanking(candidates: [
        ['bill_id' => 1, 'recurring_likelihood' => 1.0, 'stable_amount' => false, 'rationale' => 'repeats, varies'],
        ['bill_id' => 2, 'recurring_likelihood' => 0.4, 'stable_amount' => true, 'rationale' => 'fixed fee'],
    ]);

    expect(array_column($ranking->ranked(), 'bill_id'))->toBe([2, 1]);
});

test('nothing is ranked while advisory calls are switched off', function (): void {
    $settings = app(AiSettings::class);
    $settings->advisory_enabled = false;
    $settings->disclosure_accepted = false;
    app()->instance(AiSettings::class, $settings);

    expect(app(MandateCandidateRanker::class)->rank(candidateBills()))->toBeNull();
});

test('a successful classification produces advice and never an authority', function (): void {
    withAiEnabled();

    // Two questions per bill: is it recurring, and is the amount stable.
    // Bill 1 reads as recurring but variable; bill 2 as a fixed one-off.
    // Answers are keyed by the named boolean question, and must be
    // real Answer objects -- the fake passes values through unmarshalled.
    Ai::fakeClassification([
        ['decision' => new BooleanAnswer(0.9)], ['decision' => new BooleanAnswer(0.2)],
        ['decision' => new BooleanAnswer(0.1)], ['decision' => new BooleanAnswer(0.8)],
    ]);

    $ranking = app(MandateCandidateRanker::class)->rank(candidateBills());

    expect($ranking)->toBeInstanceOf(AdvisoryMandateRanking::class)
        ->and($ranking->advisoryOnly)->toBeTrue()
        ->and($ranking->toArray()['decides_payment'])->toBeFalse()
        ->and(collect($ranking->candidates)->pluck('bill_id')->all())->toBe([1, 2])
        // A recurring but variable bill ranks below a stable one even though it
        // scored higher on recurrence.
        ->and(array_column($ranking->ranked(), 'bill_id'))->toBe([2, 1]);
});

test('an empty bill list asks nothing', function (): void {
    withAiEnabled();

    expect(app(MandateCandidateRanker::class)->rank([]))->toBeNull();
});

test('a provider failure takes the whole ranking rather than a partial one', function (): void {
    // A partial ranking would read as a considered judgement about whichever
    // bills survived, which is a much stronger claim than "the model was down".
    withAiEnabled();

    Ai::fakeClassification(function (ClassificationPrompt $prompt): never {
        throw new RuntimeException('classification gateway unavailable');
    });

    expect(app(MandateCandidateRanker::class)->rank(candidateBills()))->toBeNull();
});

test('a malformed classification discards earlier advisory results too', function (): void {
    withAiEnabled();
    Ai::fakeClassification([
        ['decision' => new BooleanAnswer(0.95)],
        ['decision' => new BooleanAnswer(0.95)],
        ['decision' => new ChoiceAnswer('not-a-boolean', ['not-a-boolean' => 1.0])],
    ]);

    expect(app(MandateCandidateRanker::class)->rank(candidateBills()))->toBeNull();
});
