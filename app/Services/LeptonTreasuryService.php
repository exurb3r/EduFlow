<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Wallet;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * Live on-chain truth for the organization treasury.
 *
 * The database balance is EduFlow's own ledger. This service reads the chain
 * directly so operations screens never present a stale number as if it were
 * settled, and so the treasury row can be re-pointed at the real agent wallet.
 */
class LeptonTreasuryService
{
    public function __construct(
        private readonly ArcNetworkGateway $arc,
    ) {}

    /**
     * Raw numeric values only. Formatting belongs to the presentation layer;
     * returning pre-formatted strings here caused `(float) "24,470.00"` to
     * silently parse as 24.0.
     *
     * @return array{
     *     chain:string, chain_id:int, block:?int, rpc_host:?string, driver:string,
     *     live_available:bool, onchain_balance:?float, ledger_balance:float,
     *     drift:?float, in_sync:?bool, treasury_address:?string,
     *     address_url:?string, is_fake:bool, error:?string
     * }
     */
    public function status(?Wallet $wallet = null): array
    {
        $driver = (string) config('lepton.default', 'circle');
        $isFake = $this->arc instanceof FakeLeptonGateway;

        $address = $wallet?->address ?? $this->arc->treasuryAddress();
        $ledger = $wallet ? (float) $wallet->balance : 0.0;

        $base = [
            'chain' => $this->arc->chainCode(),
            'chain_id' => $this->arc->chainId(),
            'block' => null,
            'rpc_host' => null,
            'driver' => $driver,
            'live_available' => false,
            'onchain_balance' => null,
            'ledger_balance' => $ledger,
            'drift' => null,
            'in_sync' => null,
            'treasury_address' => $address,
            'address_url' => null,
            'is_fake' => $isFake,
            'error' => null,
        ];

        if (! $address) {
            $base['error'] = 'No treasury address configured. Set LEPTON_TREASURY_ADDRESS.';

            return $base;
        }

        $base['address_url'] = $this->safe(fn (): ?string => $this->arc->addressExplorerUrl($address));

        $block = $this->safe(fn (): ?string => $this->arc->blockNumber());
        $base['block'] = is_string($block) ? (int) hexdec(ltrim($block, '0x') ?: '0') : null;

        $rpc = $this->safe(fn (): string => $this->arc->rpcUrl());
        if (is_string($rpc) && $rpc !== '') {
            $base['rpc_host'] = parse_url($rpc, PHP_URL_HOST) ?: $rpc;
        }

        $wei = $this->safe(fn (): mixed => $this->arc->rpc('eth_getBalance', [$address, 'latest']));

        if (! is_string($wei) || ! str_starts_with($wei, '0x')) {
            $base['error'] ??= 'Live balance unavailable on '.strtoupper($base['chain']).'. Run: circle wallet login <email> --testnet --init';

            return $base;
        }

        // Native Arc USDC is 18 decimals, unlike the 6-decimal Circle USDC.
        // String arithmetic: 20 USDC is 2e19 wei, which overflows a 64-bit int.
        try {
            $native = (float) Amounts::fromHexQuantity($wei, 18);
        } catch (\InvalidArgumentException) {
            $base['error'] ??= 'Unreadable balance quantity from the RPC endpoint.';

            return $base;
        }

        $base['live_available'] = true;
        $base['onchain_balance'] = $native;
        $base['drift'] = $ledger - $native;
        $base['in_sync'] = abs($ledger - $native) < 0.01;

        return $base;
    }

    /**
     * Re-point the organization treasury at the configured Lepton agent wallet.
     */
    public function adoptConfiguredWallet(Wallet $wallet): bool
    {
        $configured = $this->arc->treasuryAddress();

        if (! $configured || strcasecmp($configured, $wallet->address) === 0) {
            return false;
        }

        $wallet->update(['address' => $configured, 'provider' => 'circle', 'network' => 'arc']);

        return true;
    }

    /**
     * Overwrite the ledger balance with the live on-chain figure.
     */
    public function syncBalance(Wallet $wallet): ?float
    {
        $status = $this->status($wallet);

        if (! $status['live_available'] || $status['onchain_balance'] === null) {
            return null;
        }

        $live = (float) $status['onchain_balance'];
        $wallet->update(['balance' => $live]);

        return $live;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return null;
        }
    }
}
