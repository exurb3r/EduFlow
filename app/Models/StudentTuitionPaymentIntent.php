<?php

declare(strict_types=1);

namespace App\Models;

use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * @property int $id
 * @property string $request_key
 * @property int $organization_id
 * @property int $quoted_for
 * @property int $student_id
 * @property int $tuition_account_id
 * @property int $student_wallet_id
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 */
class StudentTuitionPaymentIntent extends Model
{
    public const string CONSENT_TEXT = 'I authorize this one operator-controlled pilot wallet transfer on Arc testnet, for the shown principal plus no more than the shown fee ceiling. Testnet USDC does not pay official college tuition. Unknown outcomes are investigated, never automatically resent.';

    protected $fillable = ['request_key', 'organization_id', 'quoted_for', 'student_id', 'tuition_account_id', 'student_wallet_id', 'snapshot', 'snapshot_digest'];

    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'quoted_for' => 'integer', 'student_id' => 'integer',
            'tuition_account_id' => 'integer', 'student_wallet_id' => 'integer', 'snapshot' => 'array'];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $intent): void {
            if (! $intent->hasValidSnapshot()) {
                throw new LogicException('Tuition quote requires exact intact pilot evidence.');
            }
        });
        static::updating(fn (): never => throw new LogicException('Tuition quotes are immutable; request fresh consent.'));
        static::deleting(fn (): never => throw new LogicException('Tuition consent evidence cannot be deleted.'));
    }

    public function hasValidSnapshot(): bool
    {
        try {
            $s = $this->snapshot;
            foreach (['organization_id', 'quoted_for', 'student_id', 'tuition_account_id', 'student_wallet_id'] as $field) {
                if (($s[$field] ?? null) !== $this->getAttribute($field) || $this->getAttribute($field) < 1) {
                    return false;
                }
            }
            foreach (['amount_base_units', 'fee_ceiling_base_units', 'available_base_units', 'total_base_units'] as $field) {
                if (! is_int($s[$field] ?? null) || $s[$field] < 0) {
                    return false;
                }
            }
            foreach (['payer_address', 'recipient_address'] as $field) {
                if (! is_string($s[$field] ?? null) || preg_match('/^0x[0-9a-f]{40}$/D', $s[$field]) !== 1 || $s[$field] === '0x'.str_repeat('0', 40)) {
                    return false;
                }
            }

            return ($s['schema_version'] ?? null) === 1 && ($s['purpose'] ?? null) === 'student_tuition_testnet_demo'
                && ($s['request_key'] ?? null) === $this->request_key && Str::isUuid($this->request_key)
                && ($s['chain'] ?? null) === 'ARC-TESTNET' && ($s['chain_id'] ?? null) === 5042002
                && ($s['asset'] ?? null) === 'USDC' && ($s['plane'] ?? null) === 'pilot'
                && is_bool($s['simulated'] ?? null) && ($s['consent_text'] ?? null) === self::CONSENT_TEXT
                && $s['amount_base_units'] > 0 && is_string($s['quoted_network_fee'] ?? null)
                && preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,18})?$/D', $s['quoted_network_fee']) === 1
                && BigInteger::of($s['amount_base_units'])->plus($s['fee_ceiling_base_units'])->isEqualTo($s['total_base_units'])
                && $s['available_base_units'] >= $s['total_base_units']
                && Carbon::parse($s['expires_at'])->gt(Carbon::parse($s['quoted_at']))
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($s));
        } catch (Throwable) {
            return false;
        }
    }
}
