<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $receipt_budget_route_approval_id
 * @property int $collection_batch_review_id
 * @property string $review_digest
 * @property string $approval_digest
 * @property int|null $predecessor_snapshot_id
 * @property int|null $budget_snapshot_id
 * @property string $state
 * @property string|null $last_error
 */
class ReceiptPlanTrigger extends Model
{
    protected $fillable = ['organization_id', 'receipt_budget_route_approval_id', 'collection_batch_review_id',
        'review_digest', 'approval_digest', 'predecessor_snapshot_id', 'budget_snapshot_id', 'state', 'last_error'];

    protected $attributes = ['state' => 'queued'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'receipt_budget_route_approval_id' => 'integer', 'collection_batch_review_id' => 'integer',
            'predecessor_snapshot_id' => 'integer', 'budget_snapshot_id' => 'integer'];
    }

    #[\Override]
    protected static function booted(): void
    {
        static::updating(function (self $trigger): void {
            if ($trigger->isDirty(['organization_id', 'receipt_budget_route_approval_id', 'collection_batch_review_id', 'review_digest', 'approval_digest'])
                || ($trigger->getOriginal('state') === 'completed' && $trigger->isDirty())) {
                throw new LogicException('Receipt trigger identity and completed successor are immutable.');
            }
        });
        static::deleting(function (): never {
            throw new LogicException('Receipt trigger evidence cannot be deleted.');
        });
    }
}
