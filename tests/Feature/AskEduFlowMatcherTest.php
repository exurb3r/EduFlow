<?php

declare(strict_types=1);

use App\Services\AskEduFlow;
use Database\Seeders\EduFlowPlanSeeder;

/**
 * Keyword routing in the deterministic answer.
 *
 * The matcher decides which of several fixed explanations a question gets, so a
 * false positive sends a student to the wrong page of facts rather than merely
 * returning a worse answer. `str_contains` produced exactly that: "usd" is a
 * substring of "USDC", so every question quoting an amount in the currency the
 * system transacts in was answered about exchange rates.
 */
beforeEach(function (): void {
    $this->seed(EduFlowPlanSeeder::class);

    $this->ask = app(AskEduFlow::class);
});

/** Which branch of the deterministic answer handled this question. */
function topicOf(AskEduFlow $ask, string $question): string
{
    return $ask->query($question)['topic'];
}

it('routes an assistance question to the policy branch, not the rate branch', function (): void {
    // "USDC" must not read as "usd".
    expect(topicOf($this->ask, 'What are the assistance guidelines?'))->toBe('assistance_policy');
});

it('routes a split question that quotes an amount to the split branch', function (): void {
    $answer = $this->ask->answer('Why did you only send 40 USDC of my request?');

    expect($answer)->toContain('autonomous assistance limit')
        ->and($answer)->not->toContain('Rate locking guarantees');
});

it('routes a genuine US dollar question to the rate branch', function (): void {
    expect(topicOf($this->ask, 'What is the exchange rate in USD?'))->toBe('currency_conversion');
});

it('does not read aid inside paid', function (): void {
    // A paid invoice is not an assistance question.
    expect(topicOf($this->ask, 'I already paid my invoice in full'))->not->toBe('assistance_policy');
});

it('matches eligibility and escalation by stem', function (): void {
    // Whole-word matching would miss both, since the words continue.
    expect(topicOf($this->ask, 'What are the eligibility criteria?'))->toBe('assistance_policy')
        ->and(topicOf($this->ask, 'Why was my case escalated?'))->toBe('escalation');
});

it('labels a question with the branch that actually answered it', function (): void {
    // The two keyword chains used to drift apart, so a policy answer could be
    // labelled 'general'. One classifier makes that impossible by construction.
    foreach ([
        'What is my tuition balance?' => 'tuition_balance',
        'What is the exchange rate in PHP?' => 'currency_conversion',
        'What are the assistance guidelines?' => 'assistance_policy',
        'Why did you only send 40 USDC of my request?' => 'split_decision',
        'What is the attendance rate requirement?' => 'attendance',
    ] as $question => $topic) {
        expect(topicOf($this->ask, $question))->toBe($topic);
    }
});

it('still recognises the guideline singular and plural', function (): void {
    foreach (['What are the assistance guidelines?', 'What is the assistance guideline?'] as $question) {
        expect(topicOf($this->ask, $question))->toBe('assistance_policy');
    }
});

it('falls back to the menu for a question about nothing in particular', function (): void {
    $answer = $this->ask->answer('Hello there');

    expect($answer)->toContain('I can explain tuition balance');
});
