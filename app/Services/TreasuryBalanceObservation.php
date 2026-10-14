<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Wallet;
use Illuminate\Support\Facades\Cache;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;

/** Read-only, block-bound observation. Never updates the institution ledger. */
final readonly class TreasuryBalanceObservation
{
    public function __construct(private ArcNetworkGateway $arc, private ArcBalanceObservation $observations) {}

    /** @return array<string, mixed> */
    public function read(?Wallet $wallet, bool $fresh = false): array
    {
        $chain = (string) config('lepton.arc.chain', 'ARC-TESTNET');
        $chainId = (int) config('lepton.arc.chain_id', 5042002);
        $configured = config('lepton.arc.treasury');
        $address = $wallet?->address;
        $base = ['available' => false, 'is_fake' => config('lepton.default') === 'fake' || $this->arc instanceof FakeLeptonGateway,
            'chain' => $chain, 'chain_id' => $chainId, 'address' => $address, 'balance_base_units' => null,
            'observed_at' => null, 'block_number' => null, 'block_hash' => null, 'error' => null];
        if (! $wallet instanceof Wallet || $wallet->status !== 'active' || ! is_string($address)
            || preg_match('/^0x[0-9a-fA-F]{40}$/D', $address) !== 1
            || ! is_string($configured) || strcasecmp($address, $configured) !== 0) {
            return [...$base, 'error' => 'An active treasury wallet matching the configured institution address is required.'];
        }
        if ($base['is_fake']) {
            return [...$base, 'error' => 'Simulation only. Fake balances are not available money.'];
        }
        $key = 'finance.balance-observation.'.hash('sha256', $chain.':'.$chainId.':'.strtolower($address));
        $cached = $fresh ? null : Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $observation = $this->observations->capture($wallet);
            $result = [...$base, 'available' => true, 'balance_base_units' => $observation['usdc_base_units'],
                'observed_at' => $observation['observed_at'], 'block_number' => $observation['block_number'],
                'block_hash' => $observation['block_hash']];
        } catch (Throwable $exception) {
            report($exception);
            $result = [...$base, 'error' => 'Live Arc balance unavailable or chain identity unverified. Refresh before planning payment.'];
        }
        Cache::put($key, $result, now()->addSeconds(30));

        return $result;
    }
}
