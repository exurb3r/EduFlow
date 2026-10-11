<?php

declare(strict_types=1);

namespace App\Services\Advisory;

use App\Ai\Advisory\AdvisoryMandateRanking;
use App\Settings\AiSettings;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Throwable;
use UnexpectedValueException;

/**
 * Ask a cheap classification model which bills might deserve a standing
 * mandate, and return only what survives as advice.
 *
 * This is Jev -- a "System One" model reached through the Laravel AI SDK's
 * classification capability, which answers in milliseconds for a small fraction
 * of a text model's cost. Routing a ticket or picking a branch is exactly that
 * shape of problem, and it is the only shape of problem this system will ever
 * put in front of a model on the payment path.
 *
 * Three boundaries are deliberate and structural rather than conventional:
 *
 *  - **It ranks, it never authorises.** The output type has no amount, no
 *    recipient and no verdict in it, so there is nothing here a caller could
 *    promote into a payment even by mistake.
 *  - **It never sees the decision inputs.** The deterministic evaluator is a
 *    pure function of evidence; nothing this class returns is fed to it. A
 *    supervisor may use this to decide *which mandate to sign*, never whether
 *    a particular occurrence may pay.
 *  - **It fails closed.** No key, an unreachable endpoint, a timeout or a
 *    malformed answer all return null. A missing model costs a supervisor a
 *    little reading time; a model that failed *open* could quietly widen what
 *    pays without a human.
 */
final readonly class MandateCandidateRanker
{
    /** How sure the model must be before a bill is read as recurring. */
    private const float THRESHOLD = 0.75;

    public function __construct(private AiSettings $settings) {}

    /**
     * @param  list<array{id:int, vendor:string, reference:string, category:string, amount:string, history_look:string}>  $bills
     */
    public function rank(array $bills): ?AdvisoryMandateRanking
    {
        if ($bills === [] || ! $this->settings->mayCallProvider()) {
            return null;
        }

        $timeout = max(3, $this->settings->timeout_seconds);
        $candidates = [];

        foreach ($bills as $bill) {
            $text = $this->describe($bill);

            try {
                $recurring = $this->decide(
                    $text,
                    'Is this bill for a subscription or a service that repeats on a regular cycle?',
                    [
                        'true' => 'Recurring: monthly or annual hosting, utilities, licences, maintenance or a repeating service contract.',
                        'false' => 'Not recurring: one-off capital equipment, a single repair, a project or a first-time purchase.',
                    ],
                    $timeout,
                );

                $stable = $this->decide(
                    $text,
                    'Does this bill keep roughly the same amount each cycle?',
                    [
                        'true' => 'Stable: a fixed fee or a predictable recurring quantity.',
                        'false' => 'Variable: the amount changes with usage, overruns, or one-off charges.',
                    ],
                    $timeout,
                );
            } catch (Throwable $e) {
                // Any provider problem takes the whole ranking away rather than
                // returning a partial one, which would read as a considered
                // judgement about the bills that survived.
                report($e);

                return null;
            }

            $candidates[] = [
                'bill_id' => (int) $bill['id'],
                'recurring_likelihood' => $recurring ? 1.0 : 0.0,
                'stable_amount' => $stable,
                'rationale' => $recurring
                    ? 'Model reads this as a repeating obligation.'
                    : 'Model reads this as a one-off.',
            ];
        }

        return new AdvisoryMandateRanking(
            candidates: $candidates,
            narrative: 'Advisory only. A supervisor still signs any standing mandate, and each occurrence '
                .'is still decided by the deterministic evaluator against that mandate.',
        );
    }

    /**
     * One yes/no classification about one bill, read at a high bar.
     *
     * @param  array{true: string, false: string}  $criteria
     */
    private function decide(string $state, string $question, array $criteria, int $timeout): bool
    {
        $answer = Classification::of($state)
            ->question('decision', new Boolean($question, $criteria))
            ->timeout($timeout)
            ->classify()
            ->answer('decision');

        // A malformed answer invalidates the whole ranking, not just this bill.
        if (! $answer instanceof BooleanAnswer) {
            throw new UnexpectedValueException('Classification did not return a boolean answer.');
        }

        // A high bar on purpose. A bill that merely looks recurring is worse
        // than one that looks not, because the cost of a wrong yes is an
        // unattended payment, so anything below this reads as "no".
        return $answer->isTrue(self::THRESHOLD);
    }

    /**
     * @param  array{id:int, vendor:string, reference:string, category:string, amount:string, history_look:string}  $bill
     */
    private function describe(array $bill): string
    {
        return <<<TEXT
        Vendor: {$bill['vendor']}
        Bill reference: {$bill['reference']}
        Category: {$bill['category']}
        Amount: {$bill['amount']} USDC
        Payment history: {$bill['history_look']}
        TEXT;
    }
}
