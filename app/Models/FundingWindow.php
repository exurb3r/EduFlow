<?php

declare(strict_types=1);

namespace App\Models;

use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * @property int $id
 * @property string $request_key
 * @property int $organization_id
 * @property int $budget_snapshot_id
 * @property int $budget_id
 * @property int $wallet_id
 * @property int $finance_policy_activation_id
 * @property int $prepared_by
 * @property int|null $supersedes_funding_window_id
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 * @property-read FundingWindow|null $supersedes
 */
class FundingWindow extends Model
{
    protected $fillable = ['request_key', 'organization_id', 'budget_snapshot_id', 'budget_id', 'wallet_id',
        'finance_policy_activation_id', 'prepared_by', 'supersedes_funding_window_id', 'snapshot', 'snapshot_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $window): void {
            if (! $window->hasValidSnapshot()) {
                throw new LogicException('Funding windows require intact exact capacity evidence.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Funding windows are immutable; rollover requires separate reviewed recovery.');
        });
        static::deleting(function (): never {
            throw new LogicException('Funding evidence cannot be deleted.');
        });
    }

    /** The window this one was rolled over from, if any. */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_funding_window_id');
    }

    /** A window is usable only while its own recorded expiry is in the future. */
    public function isExpired(): bool
    {
        try {
            return Carbon::parse($this->snapshot['valid_until'])->lte(now());
        } catch (Throwable) {
            // Unreadable expiry is never treated as "still valid".
            return true;
        }
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'budget_snapshot_id' => 'integer', 'budget_id' => 'integer', 'wallet_id' => 'integer',
            'finance_policy_activation_id' => 'integer', 'prepared_by' => 'integer', 'supersedes_funding_window_id' => 'integer',
            'snapshot' => 'array'];
    }

    public function hasValidSnapshot(): bool
    {
        try {
            $s = $this->getAttribute('snapshot');
            if (! is_array($s) || ! is_array($s['balance'] ?? null) || ! is_array($s['capacity'] ?? null)
                || ($s['schema_version'] ?? null) !== 1 || ($s['purpose'] ?? null) !== 'department_funding_window'
                || ! Str::isUuid($this->request_key) || $this->request_key !== strtolower($this->request_key)
                || ($s['request_key'] ?? null) !== $this->request_key || ($s['institution_id'] ?? null) !== $this->organization_id
                || ($s['budget_snapshot_id'] ?? null) !== $this->budget_snapshot_id || ($s['budget_id'] ?? null) !== $this->budget_id
                || ($s['wallet_id'] ?? null) !== $this->wallet_id || ($s['policy_activation_id'] ?? null) !== $this->finance_policy_activation_id
                || ($s['prepared_by'] ?? null) !== $this->prepared_by || ($s['currency'] ?? null) !== 'USDC'
                || $this->prepared_by <= 0 || $this->organization_id <= 0 || $this->budget_snapshot_id <= 0 || $this->wallet_id <= 0
                || ($s['exclusive_treasury_attested'] ?? null) !== true || ! is_bool($s['balance']['is_fake'] ?? null)) {
                return false;
            }
            foreach (['budget_snapshot_digest', 'policy_activation_digest', 'wallet_fingerprint'] as $field) {
                if (! is_string($s[$field] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $s[$field]) !== 1) {
                    return false;
                }
            }
            foreach (['budget_base_units', 'cash_base_units', 'protected_base_units'] as $field) {
                if (! $this->unsigned($s['capacity'][$field] ?? null) || BigInteger::of($s['capacity'][$field])->isGreaterThan(PHP_INT_MAX)) {
                    return false;
                }
            }
            $b = $s['balance'];
            foreach (['native_units', 'usdc_base_units', 'native_residual_units', 'block_number', 'block_timestamp'] as $field) {
                if (! $this->unsigned($b[$field] ?? null)) {
                    return false;
                }
            }
            $native = BigInteger::of($b['native_units']);

            return $this->lineageIsSound($s)
                && in_array([$b['chain'] ?? null, $b['chain_id'] ?? null], [['ARC', 5042], ['ARC-TESTNET', 5042002]], true)
                && is_string($b['address'] ?? null) && preg_match('/^0x[0-9a-f]{40}$/D', $b['address']) === 1
                && $b['address'] !== '0x'.str_repeat('0', 40)
                && is_string($b['block_hash'] ?? null) && preg_match('/^0x[0-9a-f]{64}$/D', $b['block_hash']) === 1
                && (string) $native->quotient('1000000000000') === $b['usdc_base_units']
                && (string) $native->remainder('1000000000000') === $b['native_residual_units']
                && BigInteger::of($s['capacity']['cash_base_units'])->plus($s['capacity']['protected_base_units'])->isLessThanOrEqualTo($b['usdc_base_units'])
                && is_string($s['valid_until'] ?? null) && is_string($b['observed_at'] ?? null)
                && Carbon::parse($s['valid_until'])->gt(Carbon::parse($b['observed_at']))
                && Carbon::parse($s['valid_until'])->lte(Carbon::parse($b['observed_at'])->addMinutes(15))
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($s));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Rollover lineage, bound into the same digest as the capacity it renews.
     *
     * A window either begins a chain or continues one, and it says which. The
     * column and the snapshot block must agree: a mismatch means somebody
     * edited one and not the other, which is what the digest exists to catch.
     *
     * Renewal is also not allowed to be a backdoor extension. The predecessor's
     * recorded expiry must already be in the past, so a rollover can only
     * replace a window that genuinely ended -- it can never prolong a live one.
     * The 15-minute ceiling on the successor's own life is enforced separately,
     * by the window context, against the fresh balance observation.
     *
     * @param  array<string, mixed>  $s
     */
    private function lineageIsSound(array $s): bool
    {
        $block = $s['supersedes'] ?? null;

        if ($this->supersedes_funding_window_id === null) {
            return $block === null;
        }

        if (! is_array($block)
            || ($block['funding_window_id'] ?? null) !== $this->supersedes_funding_window_id
            || $this->supersedes_funding_window_id <= 0
            || $this->supersedes_funding_window_id === $this->id
            || ! is_string($block['snapshot_digest'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $block['snapshot_digest']) !== 1
            || ! is_string($block['valid_until'] ?? null)) {
            return false;
        }

        return Carbon::parse($block['valid_until'])->lte(now());
    }

    private function unsigned(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,77})$/D', $value) === 1;
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return ['id' => $this->id, 'snapshot' => $this->snapshot, 'snapshot_digest' => $this->snapshot_digest,
            'snapshot_valid' => $this->hasValidSnapshot(), 'payment_approved' => false, 'funds_reserved' => false,
            'can_execute' => false, 'local_accounts_changed' => false, 'is_fake' => $this->snapshot['balance']['is_fake'] ?? null];
    }
}
