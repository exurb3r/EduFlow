<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * How a rail bounds what the sender can lose in network fees.
 *
 * The distinction is not cosmetic. A rail that sets the transaction's own
 * `maxFeePerGas` enforces the accepted ceiling at signing time, and nothing
 * beyond it can ever be charged. A rail that does not still has a bound, but a
 * weaker one, and the caller has to know which it is holding before it relies
 * on it.
 */
enum FeeGuarantee: string
{
    /**
     * The signer itself caps the fee. The strongest guarantee: the accepted
     * ceiling is a property of the transaction, so it cannot be exceeded.
     */
    case SignerEnforced = 'signer_enforced';

    /**
     * The fee is bounded only by what the sender holds.
     *
     * On an EVM chain a sender must be able to cover `value + gasUsed ×
     * effectiveGasPrice`, so the fee physically cannot exceed the balance above
     * the principal. If the wallet holds nothing beyond principal + ceiling at
     * the moment of submission, total loss is capped at principal + ceiling.
     *
     * That is weaker than `SignerEnforced` in one specific, important way: the
     * fee may consume the *entire* ceiling rather than staying under it. The
     * caller must read and record which guarantee applied, because "bounded by
     * available funds" is not the same promise as "capped at the quoted fee".
     */
    case SenderBalanceBounded = 'sender_balance_bounded';

    /**
     * No bound at all. The fee is whatever the network decides, and only the
     * payer's consent stands between them and it.
     */
    case Unbounded = 'unbounded';
}
