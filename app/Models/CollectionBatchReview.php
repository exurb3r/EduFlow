<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $collection_batch_id
 * @property int $reviewed_by
 * @property string $decision
 * @property string $verification_reference
 * @property string $reason
 * @property string $batch_digest
 * @property string $review_digest
 * @property-read CollectionBatch|null $batch
 */
class CollectionBatchReview extends Model
{
    protected $fillable = ['organization_id', 'collection_batch_id', 'reviewed_by', 'decision', 'verification_reference', 'reason', 'batch_digest', 'review_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $review): void {
            /** @var CollectionBatch|null $batch */
            $batch = CollectionBatch::query()->find($review->collection_batch_id);
            if ($batch === null || ! $review->hasValidEvidence($batch)) {
                throw new LogicException('Collection review requires intact received evidence and an independent reviewer.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Collection evidence reviews are immutable.');
        });
        static::deleting(function (): never {
            throw new LogicException('Collection review evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'collection_batch_id' => 'integer', 'reviewed_by' => 'integer'];
    }

    /** @return array<string, int|string> */
    public function content(): array
    {
        return ['schema_version' => 1, 'institution_id' => $this->organization_id, 'collection_batch_id' => $this->collection_batch_id,
            'reviewed_by' => $this->reviewed_by, 'decision' => $this->decision, 'verification_reference' => $this->verification_reference,
            'reason' => $this->reason, 'batch_digest' => $this->batch_digest];
    }

    public function hasValidEvidence(CollectionBatch $batch): bool
    {
        return $batch->exists && $batch->hasValidSnapshot() && $batch->id === $this->collection_batch_id
            && $batch->organization_id === $this->organization_id && $this->reviewed_by > 0 && $this->reviewed_by !== $batch->prepared_by
            && in_array($this->decision, ['approve_receipts', 'reject', 'hold'], true)
            && trim($this->verification_reference) !== '' && mb_strlen($this->verification_reference) <= 255
            && trim($this->reason) !== '' && mb_strlen($this->reason) <= 1000
            && hash_equals($batch->snapshot_digest, $this->batch_digest)
            && hash_equals($this->review_digest, PaymentIntent::digest($this->content()));
    }

    /** @return BelongsToMany<BudgetSnapshot, $this> */
    public function budgetSnapshots(): BelongsToMany
    {
        return $this->belongsToMany(BudgetSnapshot::class, 'budget_snapshot_collection_review');
    }

    /** @return BelongsTo<CollectionBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(CollectionBatch::class, 'collection_batch_id');
    }

    /** @return HasOne<ReceiptPlanTrigger, $this> */
    public function planningTrigger(): HasOne
    {
        return $this->hasOne(ReceiptPlanTrigger::class);
    }
}
