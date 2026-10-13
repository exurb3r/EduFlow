<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $budget_snapshot_id
 * @property int $prepared_by
 * @property string $request_key
 * @property string $source_stream
 * @property string $currency
 * @property Carbon $receipts_from
 * @property Carbon $valid_until
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 * @property-read BudgetSnapshot $budgetSnapshot
 * @property-read ReceiptBudgetRouteApproval|null $approval
 */
class ReceiptBudgetRoute extends Model
{
    protected $fillable = ['request_key', 'organization_id', 'budget_snapshot_id', 'prepared_by', 'source_stream',
        'currency', 'receipts_from', 'valid_until', 'snapshot', 'snapshot_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $route): void {
            if (! $route->hasValidSnapshot()) {
                throw new LogicException('Receipt routing requires intact exact planning context.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Receipt routes are immutable; prepare a new reviewed context.');
        });
        static::deleting(function (): never {
            throw new LogicException('Receipt routing evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'budget_snapshot_id' => 'integer', 'prepared_by' => 'integer',
            'snapshot' => 'array', 'receipts_from' => 'datetime', 'valid_until' => 'datetime'];
    }

    public function hasValidSnapshot(): bool
    {
        try {
            $base = BudgetSnapshot::query()->find($this->budget_snapshot_id);
            $s = $this->snapshot;

            return $base instanceof BudgetSnapshot && ! isset($base->snapshot['receipt_routing']) && $base->hasValidSnapshot()
                && ($base->snapshot['schema_version'] ?? null) === 3 && $base->organization_id === $this->organization_id
                && $base->currency === $this->currency && $base->snapshot['amounts']['opening_funds'] === '0'
                && $this->prepared_by > 0 && Str::isUuid($this->request_key) && $this->request_key === strtolower($this->request_key)
                && in_array($this->source_stream, [CollectionBatch::TUITION_PORTAL_STREAM, CollectionBatch::BILL_PORTAL_STREAM], true)
                && $this->currency === 'USDC' && $this->receipts_from->lt($this->valid_until)
                && $this->valid_until->lte(Carbon::parse($base->snapshot['valid_until']))
                && hash_equals(PaymentIntent::digest($s), PaymentIntent::digest($this->content($base)))
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($s));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function content(BudgetSnapshot $base): array
    {
        return ['schema_version' => 1, 'purpose' => 'receipt_to_budget_planning_only', 'request_key' => $this->request_key,
            'institution_id' => $this->organization_id, 'prepared_by' => $this->prepared_by,
            'budget_snapshot_id' => $base->id, 'budget_snapshot_digest' => $base->snapshot_digest,
            'budget_id' => $base->budget_id, 'source_stream' => $this->source_stream, 'currency' => $this->currency,
            'receipts_from' => $this->receipts_from->utc()->toIso8601String(), 'valid_until' => $this->valid_until->utc()->toIso8601String(),
            'bills' => $base->snapshot['bills'], 'department' => $base->snapshot['department'],
            'can_execute' => false, 'can_allocate_budget' => false, 'can_authorize_payment' => false];
    }

    /** @return BelongsTo<BudgetSnapshot, $this> */
    public function budgetSnapshot(): BelongsTo
    {
        return $this->belongsTo(BudgetSnapshot::class);
    }

    /** @return HasOne<ReceiptBudgetRouteApproval, $this> */
    public function approval(): HasOne
    {
        return $this->hasOne(ReceiptBudgetRouteApproval::class);
    }
}
