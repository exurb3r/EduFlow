<?php

declare(strict_types=1);

namespace App\Ai\Advisory;

/**
 * A model's read on which bills might deserve a standing mandate.
 *
 * This is a reading, not a decision, and the type is shaped so it cannot become
 * one. It carries no amount of authority to spend, no recipient, no ceiling and
 * no verdict -- only a likelihood that a bill repeats, whether its amount looks
 * stable, and a short reason a human can argue with.
 *
 * The distinction matters more here than anywhere else in the product. A
 * standing mandate is the only construct that lets a payment happen with no
 * human present, so the question "should this bill pay itself?" is answered by a
 * reviewer who signs a mandate, and then by deterministic PHP on each
 * occurrence. A model's opinion can make that review faster. It cannot replace
 * it, and nothing here is shaped so that it could be mistaken for doing so.
 */
final readonly class AdvisoryMandateRanking
{
    /**
     * @param  list<array{bill_id:int, recurring_likelihood:float, stable_amount:bool, rationale:string}>  $candidates
     * @param  bool  $advisoryOnly  Always true. Present so callers can assert it.
     */
    public function __construct(
        public array $candidates,
        public string $narrative = '',
        public bool $advisoryOnly = true,
    ) {}

    /**
     * Bills worth a reviewer's attention first.
     *
     * An unstable amount is demoted rather than hidden: a bill that varies is
     * exactly the one where an unattended payment is most dangerous, and hiding
     * it would hide the warning.
     *
     * @return list<array{bill_id:int, recurring_likelihood:float, stable_amount:bool, rationale:string}>
     */
    public function ranked(): array
    {
        $candidates = $this->candidates;

        usort($candidates, fn (array $a, array $b): int => [$b['stable_amount'] ? 1 : 0, $b['recurring_likelihood']]
            <=> [$a['stable_amount'] ? 1 : 0, $a['recurring_likelihood']]);

        return $candidates;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'candidates' => $this->ranked(),
            'narrative' => $this->narrative,
            // Recorded on every row so a later reader cannot mistake this for
            // an authorisation that happened to be stored as evidence.
            'advisory_only' => true,
            'decides_payment' => false,
        ];
    }
}
