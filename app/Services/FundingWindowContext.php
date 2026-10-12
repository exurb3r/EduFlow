<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetSnapshot;
use App\Models\FinancePolicyActivation;
use App\Models\FinancePolicyVersion;
use App\Models\FundingWindow;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\Wallet;
use Brick\Math\BigInteger;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final readonly class FundingWindowContext
{
    public function __construct(private DepartmentBudgetPlanner $planner) {}

    /**
     * Rebuild a window's evidence from first principles.
     *
     * `requireCurrent` re-runs this against a stored window and compares
     * digests, so this method must describe the snapshot *exactly* — including
     * rollover lineage, which is why `$supersedes` is a parameter rather than
     * something a caller appends afterwards. A field added outside here would
     * silently invalidate every window that carried it.
     *
     * @param  array<string, mixed>  $balance
     * @return array<string, mixed>
     */
    public function build(User $actor, BudgetSnapshot $budget, Wallet $wallet, array $balance, string $key, string $validUntil, ?FundingWindow $supersedes = null): array
    {
        $this->planner->handle($actor, $budget);
        $activation = FinancePolicyActivation::current($budget->organization_id);
        /** @var FinancePolicyVersion|null $policy */
        $policy = $activation instanceof FinancePolicyActivation ? FinancePolicyVersion::query()->find($activation->finance_policy_version_id) : null;
        if (! in_array($budget->snapshot['schema_version'] ?? null, [2, 3], true) || $budget->currency !== 'USDC' || $wallet->organization_id !== $budget->organization_id || ! $wallet->exists
            || $wallet->status !== 'active' || $wallet->provider !== 'circle'
            || ! in_array($wallet->network, ['arc', ($balance['chain'] ?? null) === 'ARC' ? 'arc-mainnet' : 'arc-testnet'], true)
            || ! $activation instanceof FinancePolicyActivation || $policy === null || ! $activation->hasValidEvidence($policy) || ! $activation->hasValidHistory()
            || ($balance['address'] ?? null) !== strtolower($wallet->address)
            || ($balance['chain'] ?? null) !== config('lepton.arc.chain') || ($balance['chain_id'] ?? null) !== config('lepton.arc.chain_id')
            || Carbon::parse($validUntil)->lte(now()) || Carbon::parse($validUntil)->gt(Carbon::parse($budget->snapshot['valid_until']))
            || Carbon::parse($validUntil)->gt(Carbon::parse($balance['observed_at'])->addMinutes(15))) {
            throw ValidationException::withMessages(['funding' => 'Current reviewed policy, exact USDC budget, matching Circle wallet and bounded evidence expiry are required.']);
        }
        $configured = config('lepton.arc.treasury');
        if (is_string($configured) && $configured !== '' && strcasecmp($configured, $wallet->address) !== 0) {
            throw ValidationException::withMessages(['wallet' => 'Selected funding treasury conflicts with configured wallet.']);
        }
        $amounts = $budget->snapshot['amounts'];
        $headroom = $budget->headroom();
        $reserve = BigInteger::max($amounts['protected_reserve'], $policy->minimum_reserve_base_units);
        $protected = $reserve->plus($amounts['restricted_cash'])->plus($amounts['other_cash_commitments']);
        $localCash = BigInteger::of($headroom['cash_minor_units'])->minus($reserve->minus($amounts['protected_reserve']));
        $cash = BigInteger::min($localCash, BigInteger::of($balance['usdc_base_units'])->minus($protected));
        if ($cash->isNegative() || BigInteger::of($headroom['budget_minor_units'])->isNegative()
            || $protected->isGreaterThan(PHP_INT_MAX) || $cash->isGreaterThan(PHP_INT_MAX)) {
            throw ValidationException::withMessages(['funding' => 'Protected cash and approved allocation leave no non-negative bounded funding capacity.']);
        }

        $snapshot = ['schema_version' => 1, 'purpose' => 'department_funding_window', 'request_key' => $key,
            'institution_id' => $budget->organization_id, 'budget_snapshot_id' => $budget->id, 'budget_snapshot_digest' => $budget->snapshot_digest,
            'budget_id' => $budget->budget_id, 'wallet_id' => $wallet->id, 'wallet_fingerprint' => self::walletFingerprint($wallet),
            'prepared_by' => $actor->id, 'currency' => 'USDC', 'policy_activation_id' => $activation->id,
            'policy_activation_digest' => $activation->activation_digest, 'balance' => $balance, 'valid_until' => $validUntil,
            'capacity' => ['budget_base_units' => $headroom['budget_minor_units'], 'cash_base_units' => (string) $cash,
                'protected_base_units' => (string) $protected], 'exclusive_treasury_attested' => true];

        // A renewal names what it replaces. The block lives inside the digest
        // rather than outside it, so a window that lies about its lineage is a
        // window whose evidence does not verify.
        if ($supersedes instanceof FundingWindow) {
            $snapshot['supersedes'] = ['funding_window_id' => $supersedes->id,
                'snapshot_digest' => $supersedes->snapshot_digest,
                'valid_until' => $supersedes->snapshot['valid_until'] ?? null,
                'rollover_reason' => 'Predecessor window expired; cash re-observed for the reviewed allocation. Reserved capacity is unchanged.'];
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $balance
     * @return array{budget: BudgetSnapshot, wallet: Wallet, available_cash: string}
     */
    public function requireCurrent(User $actor, FundingWindow $window, array $balance): array
    {
        if (! $window->hasValidSnapshot() || Carbon::parse($window->snapshot['valid_until'])->lte(now())) {
            throw ValidationException::withMessages(['funding' => 'Funding window is expired or failed integrity checks; held capacity is not released.']);
        }
        /** @var BudgetSnapshot $budget */
        $budget = BudgetSnapshot::query()->where('organization_id', $window->organization_id)->findOrFail($window->budget_snapshot_id);
        /** @var Wallet $wallet */
        $wallet = Wallet::query()->where('organization_id', $window->organization_id)->findOrFail($window->wallet_id);
        $current = $this->build($actor, $budget, $wallet, $window->snapshot['balance'], $window->request_key, $window->snapshot['valid_until'], $window->supersedes);
        $current['prepared_by'] = $window->prepared_by;
        if (! hash_equals($window->snapshot_digest, PaymentIntent::digest($current))
            || ($balance['chain'] ?? null) !== $current['balance']['chain'] || ($balance['chain_id'] ?? null) !== $current['balance']['chain_id']
            || ($balance['address'] ?? null) !== $current['balance']['address'] || ($balance['is_fake'] ?? null) !== $current['balance']['is_fake']
            || BigInteger::of($balance['block_number'])->isLessThan($current['balance']['block_number'])
            || ($balance['block_number'] === $current['balance']['block_number'] && $balance['block_hash'] !== $current['balance']['block_hash'])) {
            throw ValidationException::withMessages(['funding' => 'Budget, policy, treasury or driver changed; funding window cannot authorize new holds.']);
        }
        if (Carbon::parse($balance['observed_at'])->lt(now()->subSeconds(30)) || Carbon::parse($balance['observed_at'])->isFuture()) {
            throw ValidationException::withMessages(['funding' => 'Arc balance observation expired while waiting for capacity locks; retry observation.']);
        }
        $available = BigInteger::min($current['capacity']['cash_base_units'],
            BigInteger::of($balance['usdc_base_units'])->minus($current['capacity']['protected_base_units']));
        if ($available->isNegative()) {
            throw ValidationException::withMessages(['funding' => 'Current Arc balance breaches protected capacity; no new hold permitted.']);
        }

        return ['budget' => $budget, 'wallet' => $wallet, 'available_cash' => (string) $available];
    }

    public static function walletFingerprint(Wallet $wallet): string
    {
        return PaymentIntent::digest(['institution_id' => $wallet->organization_id, 'id' => $wallet->id,
            'provider' => $wallet->provider, 'network' => $wallet->network, 'address' => strtolower($wallet->address), 'status' => $wallet->status]);
    }
}
