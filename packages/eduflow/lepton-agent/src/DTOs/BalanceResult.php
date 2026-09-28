<?php

declare(strict_types=1);

namespace Eduflow\Lepton\DTOs;

final readonly class BalanceResult
{
    /**
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public string $address,
        public int $amountBaseUnits,
        public string $chain,
        public bool $isFake = false,
        public array $raw = [],
    ) {}
}
