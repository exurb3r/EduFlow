<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Wallet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

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
    public function status(?Wallet $wallet = null, bool $fresh = false): array
    {
        $address = $wallet instanceof Wallet ? $wallet->address : $this->arc->treasuryAddress();
        $cacheKey = 'lepton_treasury_status_'.md5((string) $address);

        if (! $fresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                /** @var array{chain:string, chain_id:int, block:?int, rpc_host:?string, driver:string, live_available:bool, onchain_balance:?float, ledger_balance:float, drift:?float, in_sync:?bool, treasury_address:?string, address_url:?string, is_fake:bool, error:?string} $cached */
                return $cached;
            }
        }

        $result = $this->resolveStatus($wallet, $address);

        Cache::put($cacheKey, $result, now()->addSeconds(30));

        return $result;
    }

    /**
     * @return array{
     *     chain:string, chain_id:int, block:?int, rpc_host:?string, driver:string,
     *     live_available:bool, onchain_balance:?float, ledger_balance:float,
     *     drift:?float, in_sync:?bool, treasury_address:?string,
     *     address_url:?string, is_fake:bool, error:?string
     * }
     */
    private function resolveStatus(?Wallet $wallet, ?string $address): array
    {
        $driver = (string) config('lepton.default', 'circle');
        $isFake = $this->arc instanceof FakeLeptonGateway;

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

        $base['address_url'] = $this->safe(fn (): string => $this->arc->addressExplorerUrl($address));

        $rpc = $this->safe(fn (): string => $this->arc->rpcUrl());
        if (is_string($rpc) && $rpc !== '') {
            $base['rpc_host'] = parse_url($rpc, PHP_URL_HOST) ?: $rpc;
        }

        $block = null;
        $wei = null;

        // On real network gateways, query JSON-RPC via HTTP pool with strict timeout to avoid CLI process and pipe stalls.
        if (! $isFake && is_string($rpc) && filter_var($rpc, FILTER_VALIDATE_URL) !== false) {
            try {
                $responses = Http::pool(fn ($pool): array => [
                    $pool->as('block')->timeout(4)->post($rpc, [
                        'jsonrpc' => '2.0',
                        'id' => 1,
                        'method' => 'eth_blockNumber',
                        'params' => [],
                    ]),
                    $pool->as('balance')->timeout(4)->post($rpc, [
                        'jsonrpc' => '2.0',
                        'id' => 2,
                        'method' => 'eth_getBalance',
                        'params' => [$address, 'latest'],
                    ]),
                ]);

                if (isset($responses['block']) && $responses['block']->successful()) {
                    $block = $responses['block']->json('result');
                }
                if (isset($responses['balance']) && $responses['balance']->successful()) {
                    $wei = $responses['balance']->json('result');
                }
            } catch (Throwable) {
                // Fall back to CLI gateway below
            }
        }

        // String arithmetic, not hexdec(), which degrades to float past
        // PHP_INT_MAX and silently corrupts. ChainQuantity also strips the
        // CLI's ANSI colour and returns null for an unreadable quantity: this
        // method reports health, so it must never throw and 500 the panel.
        $base['block'] = $block !== null
            ? ChainQuantity::toInt($block)
            : ChainQuantity::toInt($this->safe(fn (): string => $this->arc->blockNumber()));

        if ($wei === null) {
            $wei = $this->safe(fn (): mixed => $this->arc->rpc('eth_getBalance', [$address, 'latest']));
        }

        // Native Arc USDC is 18 decimals, unlike the 6-decimal Circle USDC.
        // String arithmetic: 20 USDC is 2e19 wei, which overflows a 64-bit int.
        $native = ChainQuantity::toDecimalString($wei, 18);

        if ($native === null) {
            // A `0x` we could see but not parse is a formatting fault; its
            // absence means the RPC never gave us a balance at all.
            $base['error'] ??= str_contains(strtolower((string) $wei), '0x')
                ? 'Unreadable balance quantity from the RPC endpoint.'
                : 'Live balance unavailable on '.strtoupper($base['chain']).'. Run: circle wallet login <email> --testnet --init';

            return $base;
        }

        $balance = (float) $native;

        $base['live_available'] = true;
        $base['onchain_balance'] = $balance;
        $base['drift'] = $ledger - $balance;
        $base['in_sync'] = abs($ledger - $balance) < 0.01;

        return $base;
    }

    public function forgetStatus(?Wallet $wallet = null): void
    {
        $address = $wallet instanceof Wallet ? $wallet->address : $this->arc->treasuryAddress();
        Cache::forget('lepton_treasury_status_'.md5((string) $address));
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
        $this->forgetStatus($wallet);

        return true;
    }

    /**
     * Overwrite the ledger balance with the live on-chain figure.
     */
    public function syncBalance(Wallet $wallet): ?float
    {
        $status = $this->status($wallet, fresh: true);

        if (! $status['live_available'] || $status['onchain_balance'] === null) {
            return null;
        }

        $live = (float) $status['onchain_balance'];
        $wallet->update(['balance' => $live]);
        $this->forgetStatus($wallet);

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
