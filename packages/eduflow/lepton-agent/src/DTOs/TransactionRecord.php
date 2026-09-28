<?php

declare(strict_types=1);

namespace Eduflow\Lepton\DTOs;

final readonly class TransactionRecord
{
    /**
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public string $id,
        public ?string $txHash,
        public string $state,
        public ?string $operation = null,
        public array $raw = [],
    ) {}
}
