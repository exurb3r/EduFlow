<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The lifecycle of a student's tuition payment attempt.
 *
 * Every state says what the *rail* knows, not what a page is claiming.
 * `prepared`, `accepted`, `awaiting_verification` and `unknown` are ordinary
 * intermediate states: none of them may be read as money moved, and none of
 * them may be resolved by sending again.
 */
enum StudentTuitionPaymentState: string
{
    /** Recorded before a signer could be reached; nothing has been asked of the rail. */
    case PREPARED = 'prepared';

    case SUBMITTING = 'submitting';

    /** The rail accepted the submission and issued its own handle. No hash exists yet. */
    case ACCEPTED = 'accepted';

    /** The rail published an on-chain hash. Movement is asserted, not yet proven. */
    case AWAITING_VERIFICATION = 'awaiting_verification';

    /** Committed receipts, matching movement and exact amount were all confirmed. */
    case VERIFIED = 'verified';

    /** The provider refused or could not be reached. Whether money moved is not known. */
    case UNKNOWN = 'unknown';

    /** The transfer is provably not going to produce the authorized movement. */
    case FAILED = 'failed';

    /** A rehearsal on a driver that cannot move money. Never evidence of receipts. */
    case SIMULATED = 'simulated';

    public function label(): string
    {
        return match ($this) {
            self::PREPARED => 'Recorded, not yet sent',
            self::SUBMITTING => 'Submission started — do not send again',
            self::ACCEPTED => 'Accepted by the network, not yet on chain',
            self::AWAITING_VERIFICATION => 'On chain, awaiting movement proof',
            self::VERIFIED => 'Verified on chain',
            self::UNKNOWN => 'Outcome unknown — being checked',
            self::FAILED => 'Could not be completed',
            self::SIMULATED => 'Simulation only',
        };
    }

    /** What the student is told to do next, which is never "pay again" by itself. */
    public function guidance(): string
    {
        return match ($this) {
            self::PREPARED => 'This payment has not been sent yet. Keep this page open; do not record it again.',
            self::SUBMITTING => 'Submission started. A lost response must be investigated without sending again.',
            self::ACCEPTED => 'The network accepted your payment. We are waiting for the chain to publish it; this takes a moment and needs no action.',
            self::AWAITING_VERIFICATION => 'The chain has a record. We are checking it moved the exact amount before this counts.',
            self::VERIFIED => 'Exact movement verified. Allocation to the designated pilot account is recorded separately.',
            self::UNKNOWN => 'We could not confirm whether this payment moved. The existing attempt is being checked instead of sending again.',
            self::FAILED => 'This attempt did not complete. A replacement payment is a separate action, reviewed before it is recorded.',
            self::SIMULATED => 'Simulated payment. No USDC moved on any network.',
        };
    }

    /**
     * States that owe work: a worker must observe them.
     *
     * @return list<string>
     */
    public static function openStates(): array
    {
        return array_map(
            static fn (self $state): string => $state->value,
            [self::PREPARED, self::SUBMITTING, self::ACCEPTED, self::AWAITING_VERIFICATION, self::UNKNOWN],
        );
    }
}
