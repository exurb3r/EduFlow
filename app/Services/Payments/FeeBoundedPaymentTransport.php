<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * A rail that can say what it does about network fees.
 *
 * Declaring this honestly matters more than declaring it optimistically: a rail
 * that answers `Unbounded` must never be treated as safe to send on, and a rail
 * that answers `SenderBalanceBounded` must only be used when the caller has
 * itself established the balance bound. The transport cannot check the caller's
 * balance, so this interface only reports capability; enforcement lives in the
 * caller.
 */
interface FeeBoundedPaymentTransport extends AsyncPaymentTransport
{
    /**
     * The strongest guarantee this rail actually provides.
     *
     * Never inferred from the presence of an `--estimate` flag: an estimate is
     * a prediction made before the transaction exists, not a limit applied to
     * it.
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
