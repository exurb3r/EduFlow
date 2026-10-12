<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * @property int $id
 * @property string $capture_key
 * @property int $organization_id
 * @property int $budget_id
 * @property int $prepared_by
 * @property string $currency
 * @property array<string, mixed> $snapshot
 * @property string $snapshot_digest
 */
class BudgetSnapshot extends Model
{
    public const array AMOUNT_FIELDS = ['allocation', 'already_spent', 'other_budget_commitments', 'opening_funds',
        'realized_receipts', 'actual_outflows', 'restricted_cash', 'protected_reserve', 'other_cash_commitments'];

    protected $fillable = ['capture_key', 'organization_id', 'budget_id', 'prepared_by', 'currency', 'snapshot', 'snapshot_digest'];

    #[\Override]
    protected static function booted(): void
    {
        static::creating(function (self $evidence): void {
            /** @var Budget|null $budget */
            $budget = Budget::query()->find($evidence->budget_id);
            if ($budget === null || $budget->organization_id !== $evidence->organization_id || ! $evidence->hasValidSnapshot()) {
                throw new LogicException('Budget evidence needs exact intact institution context.');
            }
        });
        static::updating(function (): never {
            throw new LogicException('Budget snapshots are immutable; capture new evidence.');
        });
        static::deleting(function (): never {
            throw new LogicException('Budget evidence cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['organization_id' => 'integer', 'budget_id' => 'integer', 'prepared_by' => 'integer', 'snapshot' => 'array'];
    }

    public function hasValidSnapshot(): bool
    {
        try {
            $content = $this->getAttribute('snapshot');
            if (! is_array($content) || array_is_list($content) || ! is_array($content['amounts'] ?? null)
                || ! is_array($content['bills'] ?? null) || ! array_is_list($content['bills'])
                || ! is_array($content['department'] ?? null) || ! is_array($content['evidence'] ?? null)) {
                return false;
            }
            if (! in_array($content['schema_version'] ?? null, [1, 2], true) || ($content['purpose'] ?? null) !== 'department_budget_planning'
                || ($content['capture_key'] ?? null) !== $this->capture_key || ! Str::isUuid($this->capture_key)
                || $this->capture_key !== strtolower($this->capture_key)
                || ($content['institution_id'] ?? null) !== $this->organization_id || $this->organization_id < 1
                || ($content['budget_id'] ?? null) !== $this->budget_id || $this->budget_id < 1
                || ($content['prepared_by'] ?? null) !== $this->prepared_by || $this->prepared_by < 1
                || ($content['currency'] ?? null) !== $this->currency || CurrencyCode::tryFrom($this->currency) === null
                || ($content['commitments_exclude_selected_bills'] ?? null) !== true
                || ($content['cash_buckets_disjoint'] ?? null) !== true
                || ! is_string($content['budget_fingerprint'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/D', $content['budget_fingerprint'])) {
                return false;
            }
            foreach (self::AMOUNT_FIELDS as $field) {
                $units = $content['amounts'][$field] ?? null;
                if (! is_string($units) || ! preg_match('/^(?:0|[1-9][0-9]*)$/D', $units)
                    || BigInteger::of($units)->isGreaterThan(PHP_INT_MAX)) {
                    return false;
                }
            }
            foreach (['budget', 'cash', 'commitments'] as $field) {
                if (! is_string($content['evidence'][$field] ?? null) || trim($content['evidence'][$field]) === '') {
                    return false;
                }
            }
            if (! is_string($content['department']['name'] ?? null) || trim($content['department']['name']) === ''
                || count($content['bills']) < 1 || count($content['bills']) > 100) {
                return false;
            }
            $ids = [];
            foreach ($content['bills'] as $bill) {
                if (! is_array($bill) || ! is_int($bill['id'] ?? null) || $bill['id'] < 1 || in_array($bill['id'], $ids, true)
                    || ! is_string($bill['digest'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/D', $bill['digest'])
                    || ! is_int($bill['review_id'] ?? null) || $bill['review_id'] < 1
                    || ! is_string($bill['review_digest'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/D', $bill['review_digest'])) {
                    return false;
                }
                $ids[] = $bill['id'];
            }
            $asOf = Carbon::createFromFormat('!Y-m-d\TH:i:sP', $content['as_of']);
            $validUntil = Carbon::createFromFormat('!Y-m-d\TH:i:sP', $content['valid_until']);
            $start = Carbon::createFromFormat('!Y-m-d', $content['department']['period_start']);
            $end = Carbon::createFromFormat('!Y-m-d', $content['department']['period_end']);

            return $asOf instanceof Carbon && $validUntil instanceof Carbon && $start instanceof Carbon && $end instanceof Carbon
                && $asOf->lt($validUntil) && $start->lte($end) && $this->hasValidCollectionBindings($content)
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($content));
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $content */
    private function hasValidCollectionBindings(array $content): bool
    {
        if ($content['schema_version'] === 1) {
            return ! array_key_exists('collections', $content);
        }
        if (! is_array($content['collections'] ?? null) || ! array_is_list($content['collections']) || count($content['collections']) > 100
            || ($content['opening_funds_exclude_collections'] ?? null) !== true) {
            return false;
        }
        $received = BigInteger::zero();
        $restricted = BigInteger::zero();
        $lastReviewId = 0;
        $batchIds = [];
        foreach ($content['collections'] as $entry) {
            if (! is_array($entry) || ! is_int($entry['review_id'] ?? null) || $entry['review_id'] <= $lastReviewId
                || ! is_int($entry['batch_id'] ?? null) || $entry['batch_id'] < 1 || in_array($entry['batch_id'], $batchIds, true)) {
                return false;
            }
            foreach (['batch_digest', 'review_digest'] as $field) {
                if (! is_string($entry[$field] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $entry[$field]) !== 1) {
                    return false;
                }
            }
            foreach (['received_minor_units', 'restricted_minor_units'] as $field) {
                if (! is_string($entry[$field] ?? null) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $entry[$field]) !== 1
                    || BigInteger::of($entry[$field])->isGreaterThan(PHP_INT_MAX)) {
                    return false;
                }
            }
            if (BigInteger::of($entry['received_minor_units'])->isZero() || BigInteger::of($entry['restricted_minor_units'])->isGreaterThan($entry['received_minor_units'])) {
                return false;
            }
            $lastReviewId = $entry['review_id'];
            $batchIds[] = $entry['batch_id'];
            $received = $received->plus($entry['received_minor_units']);
            $restricted = $restricted->plus($entry['restricted_minor_units']);
        }

        return (string) $received === $content['amounts']['realized_receipts'] && $restricted->isLessThanOrEqualTo($content['amounts']['restricted_cash']);
    }

    /** @return BelongsTo<Budget, $this> */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /** @return HasMany<FinanceWorkflowRun, $this> */
    public function workflowRuns(): HasMany
    {
        return $this->hasMany(FinanceWorkflowRun::class);
    }

    /** @return BelongsToMany<CollectionBatchReview, $this> */
    public function collectionReviews(): BelongsToMany
    {
        return $this->belongsToMany(CollectionBatchReview::class, 'budget_snapshot_collection_review');
    }

    /** @return array{budget_minor_units: string, cash_minor_units: string} */
    public function headroom(): array
    {
        if (! $this->hasValidSnapshot()) {
            throw new LogicException('Invalid budget evidence cannot supply planning headroom.');
        }
        $amounts = $this->snapshot['amounts'];
        $budget = BigInteger::of($amounts['allocation'])->minus($amounts['already_spent'])->minus($amounts['other_budget_commitments']);
        $cash = BigInteger::of($amounts['opening_funds'])->plus($amounts['realized_receipts'])->minus($amounts['actual_outflows'])
            ->minus($amounts['restricted_cash'])->minus($amounts['protected_reserve'])->minus($amounts['other_cash_commitments']);

        return ['budget_minor_units' => (string) $budget, 'cash_minor_units' => (string) $cash];
    }
}
