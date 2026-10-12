<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * The rail's own identifier for a transfer it has accepted.
 *
 * This is deliberately **not** an on-chain reference. Circle agent wallets
 * settle asynchronously: the CLI returns a transaction id immediately and the
 * `0x…` hash exists only once the transaction is complete. Carrying a handle in
 * the same column as a hash would make an in-flight payment indistinguishable
 * from a settled one, and an unresolvable hash is exactly what
 * `ArcSettlementVerifier` treats as "not found" — an accusation rather than a
 * waiting state.
 */
final readonly class TransportAcceptance
{
    public function __construct(
        public string $handle,
        /** @var array<string, mixed> */
        public array $raw = [],
    ) {}
}
