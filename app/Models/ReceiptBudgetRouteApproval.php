<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $receipt_budget_route_id
 * @property int $approved_by
 * @property string $route_digest
 * @property string $reason
 * @property string $approval_digest
 * @property-read ReceiptBudgetRoute $route
 */
class ReceiptBudgetRouteApproval extends Model
{
    protected $fillable = ['organization_id', 'receipt_budget_route_id', 'approved_by', 'route_digest', 'reason', 'approval_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $approval): void {
            $route = ReceiptBudgetRoute::query()->find($approval->receipt_budget_route_id);
            if (! $route instanceof ReceiptBudgetRoute || ! $approval->hasValidEvidence($route)) {
                throw new LogicException('Receipt route approval requires exact independent review.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Receipt route approvals are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Receipt route approvals cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'receipt_budget_route_id' => 'integer', 'approved_by' => 'integer'];
    }

    /** @return array<string, int|string|bool> */
    public function content(): array
    {
        return ['schema_version' => 1, 'institution_id' => $this->organization_id, 'route_id' => $this->receipt_budget_route_id,
            'approved_by' => $this->approved_by, 'route_digest' => $this->route_digest, 'reason' => $this->reason,
            'can_execute' => false, 'can_authorize_payment' => false];
    }

    public function hasValidEvidence(ReceiptBudgetRoute $route): bool
    {
        return $route->hasValidSnapshot() && $route->id === $this->receipt_budget_route_id
            && $route->organization_id === $this->organization_id && $this->approved_by > 0
            && $this->approved_by !== $route->prepared_by && $this->approved_by !== $route->budgetSnapshot->prepared_by
            && $this->route_digest === $route->snapshot_digest && trim($this->reason) !== '' && mb_strlen($this->reason) <= 1000
            && hash_equals($this->approval_digest, PaymentIntent::digest($this->content()));
    }

    /** @return BelongsTo<ReceiptBudgetRoute, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(ReceiptBudgetRoute::class, 'receipt_budget_route_id');
    }
}
