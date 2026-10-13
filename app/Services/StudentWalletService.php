<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\StudentWalletSnapshot;
use App\DTOs\WalletReadState;
use App\Models\Student;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;
use Yukazakiri\Lepton\Contracts\ArcNetworkGateway;
use Yukazakiri\Lepton\Contracts\WalletGateway;
use Yukazakiri\Lepton\DTOs\BalanceResult;
use Yukazakiri\Lepton\Gateways\FakeLeptonGateway;
use Yukazakiri\Lepton\Support\Amounts;

/**
 * Live on-chain truth for a student's wallet.
 *
 * The student's payout address is the wallet. There is no separate stored
 * balance: a number written to the database drifts from the chain, and this
 * application treats a stale figure presented as settled as a defect. Every
 * read therefore goes to the chain, is cached only for the TTL below, and
 * reports why it failed rather than showing a zero balance as real.
 */
class StudentWalletService
{
    public function __construct(
        private readonly ArcNetworkGateway $arc,
        private readonly WalletGateway $wallets,
        private readonly Factory $cache,
    ) {}

    /**
     * The payout address, but only when it is a usable Arc destination.
     *
     * A malformed or missing address must never fall back to a generated one:
     * a rejected address is recoverable, an accepted address the student does
     * not control is not.
     */
    public function addressFor(Student $student): ?string
    {
        $address = $student->payout_address;

        if (! is_string($address) || preg_match(Student::PAYOUT_ADDRESS_PATTERN, $address) !== 1) {
            return null;
        }

        return $address;
    }

    /**
     * The only writer of a student's wallet address.
     *
     * Both the student portal and the Filament staff form go through here so
     * the strict pattern and the value written are defined in one place.
     */
    public function setAddress(Student $student, string $address): void
    {
        $address = trim($address);

        if (preg_match(Student::PAYOUT_ADDRESS_PATTERN, $address) !== 1) {
            throw new InvalidArgumentException('Wallet address must be 0x followed by exactly 40 hex characters.');
        }

        $student->update(['payout_address' => $address]);
        $this->forget($student);
    }

    /**
     * The current wallet state, cached so a page of screens does not turn into
     * a page of RPC calls.
     */
    public function snapshot(Student $student): StudentWalletSnapshot
    {
        $address = $this->addressFor($student);

        if ($address === null) {
            return $student->payout_address === null || $student->payout_address === ''
                ? StudentWalletSnapshot::missing()
                : StudentWalletSnapshot::invalid();
        }

        $ttl = (int) config('eduflow.wallet_balance_cache_seconds', 30);

        return $this->cache->store()->remember(
            $this->cacheKey($student),
            max($ttl, 1),
            fn (): StudentWalletSnapshot => $this->read($address, $this->arc instanceof FakeLeptonGateway),
        );
    }

    /**
     * Drop the cached reading so the next snapshot hits the chain.
     */
    public function forget(Student $student): void
    {
        $this->cache->store()->forget($this->cacheKey($student));
    }

    /**
     * The chain student wallets live on, taken from the Arc configuration.
     */
    public function chain(): string
    {
        return (string) config('lepton.arc.chain', 'ARC-TESTNET');
    }

    private function cacheKey(Student $student): string
    {
        return 'student_wallet:'.$student->id;
    }

    private function read(string $address, bool $isFake): StudentWalletSnapshot
    {
        $chain = $this->arc->chainCode();
        $explorerUrl = $this->safe(fn (): string => $this->arc->addressExplorerUrl($address));

        if ($isFake) {
            // The fake driver keeps its own ledger in 6-decimal base units, the
            // same precision the Circle CLI uses, so it is read that way.
            $result = $this->safe(fn (): mixed => $this->wallets->balance($address, ['chain' => $chain]));

            if (! $result instanceof BalanceResult) {
                return $this->unavailable($address, $chain, $explorerUrl, true, 'Fake driver could not return a balance.');
            }

            $balance = Amounts::toDecimalString($result->amountBaseUnits, 6);

            return new StudentWalletSnapshot(
                state: WalletReadState::OK,
                address: $address,
                balance: $balance,
                chain: $chain,
                explorerUrl: $explorerUrl,
                isFake: true,
                reason: null,
                cachedAt: now()->toIso8601String(),
            );
        }

        $wei = null;

        $rpc = $this->safe(fn (): string => $this->arc->rpcUrl()) ?: config('lepton.arc.rpc_url');

        if (is_string($rpc) && filter_var($rpc, FILTER_VALIDATE_URL) !== false) {
            try {
                $response = Http::timeout(5)->post($rpc, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'eth_getBalance',
                    'params' => [$address, 'latest'],
                ]);

                if ($response->successful() && is_string($response->json('result'))) {
                    $wei = $response->json('result');
                }
            } catch (Throwable) {
                // Fall back to CLI gateway below
            }
        }

        if ($wei === null) {
            $wei = $this->safe(fn (): mixed => $this->arc->rpc('eth_getBalance', [$address, 'latest']));
        }

        if (! is_string($wei) || ! str_starts_with($wei, '0x')) {
            return $this->unavailable(
                $address,
                $chain,
                $explorerUrl,
                false,
                'Live balance unavailable on '.strtoupper($chain).'. Confirm the Lepton CLI is authenticated for '.strtoupper($chain).'.',
            );
        }

        // Native Arc USDC is 18 decimals, so a realistic balance overflows a
        // 64-bit integer. String arithmetic only: never hexdec() a quantity.
        try {
            $balance = Amounts::fromHexQuantity($wei, 18);
        } catch (InvalidArgumentException) {
            return $this->unavailable($address, $chain, $explorerUrl, false, 'Unreadable balance quantity from the RPC endpoint.');
        }

        return new StudentWalletSnapshot(
            state: WalletReadState::OK,
            address: $address,
            balance: $balance,
            chain: $chain,
            explorerUrl: $explorerUrl,
            isFake: false,
            reason: null,
            cachedAt: now()->toIso8601String(),
        );
    }

    private function unavailable(
        string $address,
        string $chain,
        ?string $explorerUrl,
        bool $isFake,
        string $reason,
    ): StudentWalletSnapshot {
        return new StudentWalletSnapshot(
            state: WalletReadState::UNAVAILABLE,
            address: $address,
            balance: null,
            chain: $chain,
            explorerUrl: $explorerUrl,
            isFake: $isFake,
            reason: $reason,
            cachedAt: now()->toIso8601String(),
        );
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
