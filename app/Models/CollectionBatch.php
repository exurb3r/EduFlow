<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * @property int $id
 * @property string $capture_key
 * @property int $organization_id
 * @property int $prepared_by
 * @property string $source_stream
 * @property string $source_reference
 * @property string $source_document_digest
 * @property string $currency
 * @property int $received_minor_units
 * @property int $restricted_minor_units
 * @property Carbon $collected_from
 * @property Carbon $collected_until
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 * @property Carbon $created_at
 * @property-read User|null $preparer
 * @property-read Collection<int, CollectionBatchReview> $reviews
 * @property-read Collection<int, FinanceWorkflowRun> $workflowRuns
 */
class CollectionBatch extends Model
{
    protected $fillable = ['capture_key', 'organization_id', 'prepared_by', 'source_stream', 'source_reference', 'source_document_digest',
        'currency', 'received_minor_units', 'restricted_minor_units', 'collected_from', 'collected_until', 'snapshot', 'snapshot_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $batch): void {
            if (! $batch->hasValidSnapshot()) {
                throw new LogicException('Collection batches require exact intact received-funds evidence.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Collection batches are immutable; corrections require reviewed successor evidence.');
        });
        static::deleting(function (): never {
            throw new LogicException('Collection source evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'prepared_by' => 'integer', 'received_minor_units' => 'integer',
            'restricted_minor_units' => 'integer', 'collected_from' => 'datetime', 'collected_until' => 'datetime', 'snapshot' => 'array'];
    }

    /** @return HasMany<FinanceWorkflowRun, $this> */
    public function workflowRuns(): HasMany
    {
        return $this->hasMany(FinanceWorkflowRun::class);
    }

    /** @return HasMany<CollectionBatchReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(CollectionBatchReview::class);
    }

    /** @return BelongsTo<User, $this> */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function getCollectedFromAttribute(string $value): Carbon
    {
        return Carbon::parse($value, 'UTC');
    }

    public function getCollectedUntilAttribute(string $value): Carbon
    {
        return Carbon::parse($value, 'UTC');
    }

    public function hasValidSnapshot(): bool
    {
        try {
            $s = $this->getAttribute('snapshot');
            if (! is_array($s) || ($s['schema_version'] ?? null) !== 1 || ($s['purpose'] ?? null) !== 'aggregate_realized_fee_receipts'
                || ! Str::isUuid($this->capture_key) || $this->capture_key !== strtolower($this->capture_key)
                || ($s['capture_key'] ?? null) !== $this->capture_key || ($s['institution_id'] ?? null) !== $this->organization_id
                || ($s['prepared_by'] ?? null) !== $this->prepared_by || $this->organization_id < 1 || $this->prepared_by < 1
                || ($s['source_stream'] ?? null) !== $this->source_stream || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $this->source_stream) !== 1
                || ($s['source_reference'] ?? null) !== $this->source_reference || trim($this->source_reference) === ''
                || ($s['source_document_digest'] ?? null) !== $this->source_document_digest || preg_match('/^[0-9a-f]{64}$/D', $this->source_document_digest) !== 1
                || ($s['currency'] ?? null) !== $this->currency || CurrencyCode::tryFrom($this->currency) === null
                || ($s['source_stream_disjoint_attested'] ?? null) !== true || ($s['received_not_forecast_attested'] ?? null) !== true
                || ! $this->hasValidDocumentMetadata($s)) {
                return false;
            }
            foreach (['received_minor_units', 'restricted_minor_units'] as $field) {
                $raw = $this->getAttributes()[$field] ?? null;
                if ((! is_string($raw) && ! is_int($raw)) || preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) $raw) !== 1
                    || BigInteger::of($raw)->isGreaterThan(PHP_INT_MAX) || ($s[$field] ?? null) !== (string) $this->getAttribute($field)) {
                    return false;
                }
            }

            return $this->received_minor_units > 0 && $this->restricted_minor_units >= 0 && $this->restricted_minor_units <= $this->received_minor_units
                && ($s['collected_from'] ?? null) === $this->collected_from->utc()->toIso8601String()
                && ($s['collected_until'] ?? null) === $this->collected_until->utc()->toIso8601String()
                && $this->collected_from->lt($this->collected_until)
                && is_string($s['cash_evidence_reference'] ?? null) && trim($s['cash_evidence_reference']) !== ''
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($s));
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function hasValidDocumentMetadata(array $snapshot): bool
    {
        if (! array_key_exists('source_document', $snapshot)) {
            return true;
        }

        $document = $snapshot['source_document'];

        return is_array($document) && ($document['disk'] ?? null) === 'local'
            && ($document['path'] ?? null) === 'collection-evidence/'.$this->organization_id.'/'.$this->capture_key.'/'.$this->source_document_digest
            && is_string($document['name'] ?? null) && trim($document['name']) !== '' && mb_strlen($document['name']) <= 255
            && preg_match('/[\x00-\x1f\x7f\/\\\\]/', $document['name']) === 0
            && in_array($document['mime_type'] ?? null, ['application/pdf', 'image/jpeg', 'image/png', 'text/csv', 'text/plain', 'application/csv'], true)
            && is_int($document['size_bytes'] ?? null) && $document['size_bytes'] > 0 && $document['size_bytes'] <= 10 * 1024 * 1024;
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        /** @var CollectionBatchReview|null $review */
        $review = CollectionBatchReview::query()->where('collection_batch_id', $this->id)->first();
        $approved = $review !== null && $review->hasValidEvidence($this) && $review->decision === 'approve_receipts';

        return ['id' => $this->id, 'snapshot' => $this->snapshot, 'snapshot_digest' => $this->snapshot_digest,
            'snapshot_valid' => $this->hasValidSnapshot(), 'review' => $review?->content(), 'review_digest' => $review?->review_digest,
            'receipts_reviewed' => $approved, 'unrestricted_minor_units' => $approved ? (string) ($this->received_minor_units - $this->restricted_minor_units) : '0',
            'verification_scope' => 'independent_staff_attestation', 'bank_balance_verified' => false,
            'arc_funding_verified' => false, 'can_execute' => false, 'accounts_changed' => false];
    }
}
