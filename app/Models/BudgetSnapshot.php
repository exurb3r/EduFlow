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
            if (! in_array($content['schema_version'] ?? null, [1, 2, 3], true) || ($content['purpose'] ?? null) !== 'department_budget_planning'
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
                && $this->hasValidReceiptRouting($content)
                && hash_equals($this->snapshot_digest, PaymentIntent::digest($content));
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $content */
    private function hasValidReceiptRouting(array $content): bool
    {
        if (! array_key_exists('receipt_routing', $content)) {
            return true;
        }
        $link = $content['receipt_routing'];
        if (! is_array($link) || ($link['schema_version'] ?? null) !== 1
            || ($link['service_capability'] ?? null) !== 'receipt_successor_planning' || ($link['can_execute'] ?? null) !== false) {
            return false;
        }
        $trigger = ReceiptPlanTrigger::query()->find($link['trigger_id'] ?? null);
        $approval = ReceiptBudgetRouteApproval::query()->find($link['approval_id'] ?? null);
        $predecessor = self::query()->find($link['predecessor_snapshot_id'] ?? null);
        $review = CollectionBatchReview::query()->find($link['collection_review_id'] ?? null);
        if (! $trigger instanceof ReceiptPlanTrigger || ! $approval instanceof ReceiptBudgetRouteApproval
            || ! $predecessor instanceof self || ! $review instanceof CollectionBatchReview
            || $predecessor->id === $this->id || ($this->exists && $predecessor->id >= $this->id)
            || ! $predecessor->hasValidSnapshot() || ! $approval->hasValidEvidence($approval->route)
            || $trigger->organization_id !== $this->organization_id || $approval->organization_id !== $this->organization_id
            || $review->organization_id !== $this->organization_id || $predecessor->budget_id !== $this->budget_id
            || $trigger->receipt_budget_route_approval_id !== $approval->id || $trigger->collection_batch_review_id !== $review->id
            || $trigger->review_digest !== $review->review_digest || $trigger->approval_digest !== $approval->approval_digest
            || $link['route_id'] !== $approval->receipt_budget_route_id || $link['approval_digest'] !== $approval->approval_digest
            || $link['predecessor_digest'] !== $predecessor->snapshot_digest || $link['review_digest'] !== $review->review_digest
            || ($trigger->state === 'completed' && $trigger->budget_snapshot_id !== $this->id)
            || ! in_array($trigger->state, ['queued', 'completed'], true)) {
            return false;
        }
        $route = $approval->route;
        $base = $route->budgetSnapshot;
        $batch = $review->batch;
        if ($batch === null || ! $review->hasValidEvidence($batch) || $review->decision !== 'approve_receipts'
            || $this->prepared_by !== $route->prepared_by || $this->currency !== $route->currency
            || $this->budget_id !== $base->budget_id || $content['bills'] !== $base->snapshot['bills']
            || ($predecessor->id !== $base->id && ($predecessor->snapshot['receipt_routing']['approval_id'] ?? null) !== $approval->id)
            || ($trigger->state === 'completed' && $trigger->predecessor_snapshot_id !== $predecessor->id)
            || $batch->source_stream !== $route->source_stream || ! $batch->isMovementSourced()
            || $batch->collected_from->lt($route->receipts_from) || $batch->collected_until->gt($route->valid_until)
            || $content['valid_until'] !== $route->valid_until->utc()->toIso8601String()
            || Carbon::parse($content['valid_until'])->gt(Carbon::parse($predecessor->snapshot['valid_until']))
            || Carbon::parse($content['as_of'])->lt($batch->collected_until)
            || Carbon::parse($content['as_of'])->lt(Carbon::parse($predecessor->snapshot['as_of']))) {
            return false;
        }
        foreach (['bills', 'department', 'budget_fingerprint', 'evidence'] as $field) {
            if ($content[$field] !== $predecessor->snapshot[$field]) {
                return false;
            }
        }
        foreach (self::AMOUNT_FIELDS as $field) {
            $increment = match ($field) {
                'realized_receipts' => $batch->received_minor_units, 'restricted_cash' => $batch->restricted_minor_units, default => 0,
            };
            if ($content['amounts'][$field] !== (string) BigInteger::of($predecessor->snapshot['amounts'][$field])->plus($increment)) {
                return false;
            }
        }
        $before = array_column($predecessor->snapshot['collections'], 'review_id');
        if (in_array($review->id, $before, true)) {
            return false;
        }
        $before[] = $review->id;
        sort($before, SORT_NUMERIC);

        return array_column($content['collections'], 'review_id') === $before;
    }

    /**
     * Whether the opening balance provably excludes the cash the receipts count.
     *
     * `opening_funds_exclude_collections` is a checkbox, and a checkbox is a
     * promise rather than a proof. For the receipts where the proof can be had,
     * it is had: `movement_receipts_minor_units` is the total this plan counts
     * from receipts whose money is **already in the treasury balance** because
     * a chain transfer settled it. A typed opening balance cannot be decomposed
     * to prove it excludes them, so when that total is non-zero the opening
     * balance must be zero and the plan starts from its receipts instead.
     *
     * Other opening balances retain their existing staff-attestation contract;
     * this does not claim to reconcile arbitrary bank statements or FX.
     *
     * Enforced from v3 only. A v2 snapshot predates the field and is read under
     * its own rule rather than being retroactively invalidated.
     *
     * @param  array<string, mixed>  $content
     */
    private function openingFundsAreMachineDisjoint(array $content): bool
    {
        if (($content['schema_version'] ?? null) !== 3) {
            return true;
        }

        $movement = $content['movement_receipts_minor_units'] ?? null;

        if (! is_string($movement) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $movement) !== 1
            || BigInteger::of($movement)->isGreaterThan(PHP_INT_MAX)) {
            return false;
        }

        $received = BigInteger::of($movement);

        // It may not exceed the receipts the plan actually counts, or the
        // recorded movement total is a figure no bound evidence supports.
        if ($received->isGreaterThan(BigInteger::of($content['amounts']['realized_receipts'] ?? '0'))) {
            return false;
        }

        if ($received->isZero()) {
            return true;
        }

        return BigInteger::of($content['amounts']['opening_funds'])->isZero();
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
        if (! $this->openingFundsAreMachineDisjoint($content)) {
            return false;
        }
        $received = BigInteger::zero();
        $restricted = BigInteger::zero();
        $movementTotal = BigInteger::zero();
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

            $movement = $content['schema_version'] === 3 ? $entry['movement_receipt_minor_units'] ?? null : 0;

            if (! is_int($movement) || ($movement !== 0 && (string) $movement !== $entry['received_minor_units'])) {
                return false;
            }

            $movementTotal = $movementTotal->plus($movement);
        }

        // The recorded movement total must be the sum of what the entries claim,
        // not an independently typed figure the exclusion rule then trusts.
        if ($content['schema_version'] === 3 && (string) $movementTotal !== $content['movement_receipts_minor_units']) {
            return false;
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
