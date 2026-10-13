<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * What a rail actually does with network fees, measured rather than assumed.
 *
 * These three states are not interchangeable, and the middle one is not a
 * weaker form of the first — it is a *different fact about who pays*. Every
 * case here was derived from real ARC-TESTNET receipts, not from how EVM
 * transfers are usually shaped, because this rail is not usually shaped.
 *
 * The trap: a Circle agent wallet is a **relayed** SCA transfer. The receipt's
 * `from` is a relayer, `to` is the W3S relay contract, `value` is zero, and the
 * payer appears only as the `from` topic of a Transfer log. So the familiar
 * "sender must hold value + gas, therefore the balance bounds the fee" argument
 * does not apply — the payer is not the account paying gas.
 *
 * Measured on three real relayed transfers (treasury → vendor, ARC-TESTNET):
 *
 * | tx                  | principal | payer's balance delta | receipt fee |
 * | ------------------- | --------- | --------------------- | ----------- |
 * | 0x9e35875787…       | 30.00     | 30.000000000000000000 | 0.006351425 |
 * | 0x94411f098c…       | 45.00     | 45.000000000000000000 | 0.003811550 |
 * | 0xcecac1d952…       | 0.01      | 0.010000000000000000 | 0.003185650 |
 *
 * Delta minus principal is **zero** in every case. The receipt fee is real, but
 * it is paid by Circle: a second Transfer log shows the relay contract
 * reimbursing the relayer. The payer's total debit is the principal and nothing
 * else.
 */
enum FeeGuarantee: string
{
    /**
     * The transaction itself carries the ceiling.
     *
     * The strongest guarantee, and the only one where the accepted ceiling is a
     * property of the transaction: `maxFeePerGas` bounds what can be charged, so
     * the payer's debit cannot exceed principal plus the ceiling no matter what
     * the network does.
     */
    case SignerEnforced = 'signer_enforced';

    /**
     * The rail sponsors the gas, so the payer's debit is the principal alone.
     *
     * This is not "a smaller fee"; it is **no fee to the payer**. Affordability
     * is therefore a comparison against the principal, never against principal
     * plus a ceiling, and a wallet holding exactly the amount can send it.
     *
     * A rail that reports this MUST have it verified against real receipts. An
     * unverified claim here would let a caller under-fund a wallet by an amount
     * it wrongly believes it will never be charged.
     */
    case RelayerSponsored = 'relayer_sponsored';

    /**
     * The payer pays the fee and nothing bounds it.
     *
     * The fee is whatever the network decides, and only the payer's consent
     * stands between them and it. No caller may submit on this without an
     * explicit, separate decision.
     */
    case Unbounded = 'unbounded';
}
