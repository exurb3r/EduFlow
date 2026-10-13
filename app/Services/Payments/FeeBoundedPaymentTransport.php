<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * A rail that can say what it does about network fees.
 *
 * Declaring this honestly matters more than declaring it optimistically. The
 * caller has to choose between two genuinely different facts — "the
 * transaction caps the fee" and "the payer pays no fee" — and those imply
 * different affordability arithmetic, so collapsing them would either overstate
 * a rail's safety or understate what a payer is being asked to accept.
 *
 * The transport reports capability only. It cannot see the caller's balance or
 * the amount being sent, so enforcement lives in the caller.
 */
interface FeeBoundedPaymentTransport extends AsyncPaymentTransport
{
    /**
     * The strongest guarantee this rail actually provides.
     *
     * Never inferred from the presence of an `--estimate` flag: an estimate is
     * a prediction made before the transaction exists, not a fact about who
     * ends up paying.
     */
    public function feeGuarantee(): FeeGuarantee;

    /**
     * Whether the rail can set the transaction's own fee ceiling.
     *
     * Convenience over `feeGuarantee() === FeeGuarantee::SignerEnforced`, kept
     * because most call sites genuinely only care about the strong case.
     */
    public function supportsFeeCeiling(): bool;
}
