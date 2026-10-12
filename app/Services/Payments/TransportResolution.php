<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * What the rail knows about a transfer it previously accepted.
 *
 * A resolution is only ever an *observation*. `pending` means the rail has not
 * finished thinking about it, which is an ordinary state for an async wallet and
 * never evidence that anything is wrong.
 */
final readonly class TransportResolution
{
    /**
     * @param  'pending'|'completed'|'failed'|'unknown'  $state
     */
    private function __construct(
        public string $state,
        public ?string $txHash = null,
        public ?string $reason = null,
        /** @var array<string, mixed> */
        public array $raw = [],
    ) {}

    /** @param array<string, mixed> $raw */
    public static function pending(array $raw = []): self
    {
        return new self('pending', reason: 'The rail has not reached a terminal state for this transfer.', raw: $raw);
    }

    /** @param array<string, mixed> $raw */
    public static function completed(string $txHash, array $raw = []): self
    {
        return new self('completed', txHash: $txHash, raw: $raw);
    }

    /** @param array<string, mixed> $raw */
    public static function failed(string $reason, array $raw = []): self
    {
        return new self('failed', reason: $reason, raw: $raw);
    }

    /**
     * The rail could not be asked.
     *
     * Distinct from `failed`: an unreachable provider says nothing about the
     * transfer, and collapsing the two would invent a conclusion from a network
     * error.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function unknown(string $reason, array $raw = []): self
    {
        return new self('unknown', reason: $reason, raw: $raw);
    }

    public function hasHash(): bool
    {
        return $this->state === 'completed' && is_string($this->txHash) && $this->txHash !== '';
    }
}
