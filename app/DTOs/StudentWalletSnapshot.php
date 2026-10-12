<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * A student's on-chain wallet state at a point in time.
 *
 * A read-only value object. It is either a real chain reading or an explicit
 * statement of why one could not be taken - never a zero balance presented
 * as if the chain had been reached.
 */
final readonly class StudentWalletSnapshot
{
    /**
     * @param  string  $balance  Exact decimal string, e.g. "12.34".
     */
    public function __construct(
        public WalletReadState $state,
        public ?string $address,
        public ?string $balance,
        public string $chain,
        public ?string $explorerUrl,
        public bool $isFake,
        public ?string $reason,
        public ?string $cachedAt,
    ) {}

    public static function forState(WalletReadState $state, ?string $reason = null): self
    {
        return new self(
            state: $state,
            address: null,
            balance: null,
            chain: (string) config('lepton.arc.chain', 'ARC-TESTNET'),
            explorerUrl: null,
            isFake: false,
            reason: $reason,
            cachedAt: null,
        );
    }

    public static function missing(): self
    {
        return self::forState(
            WalletReadState::MISSING,
            'No wallet address on file. Link a payout wallet before funds can be received.',
        );
    }

    public static function invalid(): self
    {
        return self::forState(
            WalletReadState::INVALID,
            'The stored address is not a valid Arc destination. It must be 0x followed by 40 hex characters.',
        );
    }

    public function isReadable(): bool
    {
        return $this->state === WalletReadState::OK;
    }

    public function hasAddress(): bool
    {
        return $this->address !== null && $this->address !== '';
    }

    /**
     * Presentation payload for the Filament widget and the Inertia page.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'address' => $this->address,
            'balance' => $this->balance,
            'chain' => $this->chain,
            'explorer_url' => $this->explorerUrl,
            'is_fake' => $this->isFake,
            'is_available' => $this->isReadable(),
            'state' => $this->state->value,
            'reason' => $this->reason,
            'cached_at' => $this->cachedAt,
        ];
    }
}
