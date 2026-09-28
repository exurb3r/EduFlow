<?php

declare(strict_types=1);

namespace Eduflow\Lepton\DTOs;

final readonly class TransferResult
{
    /**
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public string $txHash,
        public string $fromAddress,
        public string $toAddress,
        public int $amountBaseUnits,
        public string $chain,
        public bool $isFake = false,
        public ?string $explorerUrl = null,
        public array $raw = [],
    ) {}
}
