<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\InvoiceVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\ReceiptBudgetRoute;
use App\Models\ReceiptBudgetRouteApproval;
use App\Models\ReceiptPlanTrigger;
use Brick\Math\BigInteger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Throwable;

/** Institution-scoped service capability: creates proposals, never impersonates staff or authorizes spending. */
final readonly class ReceiptTriggeredPlanning
{
    public function __construct(private InstallationInstitution $institutions, private DepartmentBudgetPlanner $planner,
        private ReviewedCollections $collections) {}

    private function enabled(): bool
    {
        return (bool) config('eduflow.background_finance.enabled', false)
            && (bool) config('eduflow.background_finance.receipt_routing_enabled', false);
    }

    /** @return Collection<int, CollectionBatchReview> */
    public function pendingReviews(): Collection
    {
        if (! $this->enabled()) {
            return new Collection;
        }
        $institution = $this->institutions->require();
        $routes = ReceiptBudgetRoute::query()->where('organization_id', $institution->id)->whereHas('approval')->get();
        if ($routes->isEmpty()) {
            return new Collection;
        }

        return CollectionBatchReview::query()->where('organization_id', $institution->id)->where('decision', 'approve_receipts')
            ->where(fn (Builder $query): Builder => $query->whereDoesntHave('planningTrigger')
                ->orWhereHas('planningTrigger', fn (Builder $trigger): Builder => $trigger->where('state', 'queued')))
            ->whereHas('batch', function (Builder $batch) use ($routes): void {
                $batch->where(function (Builder $coverage) use ($routes): void {
                    foreach ($routes as $route) {
                        $coverage->orWhere(fn (Builder $source): Builder => $source->where('source_stream', $route->source_stream)
                            ->where('currency', $route->currency)->where('collected_from', '>=', $route->receipts_from)
                            ->where('collected_until', '<=', $route->valid_until));
                    }
                });
            })->orderBy('id')->limit(100)->get();
    }

    /** Review is the durable event; receipt arrival alone supplies no planning cash. */
    public function record(CollectionBatchReview $review): ?ReceiptPlanTrigger
    {
        if (! $this->enabled()) {
            return null;
        }
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($review, $institution): ?ReceiptPlanTrigger {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            $review = CollectionBatchReview::query()->where('organization_id', $institution->id)->findOrFail($review->id);
            $batch = CollectionBatch::query()->where('organization_id', $institution->id)->findOrFail($review->collection_batch_id);
            $existing = ReceiptPlanTrigger::query()->where('collection_batch_review_id', $review->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($review->decision !== 'approve_receipts' || ! $review->hasValidEvidence($batch) || ! $batch->isMovementSourced()) {
                return null;
            }
            $routes = ReceiptBudgetRoute::query()->where('organization_id', $institution->id)->where('source_stream', $batch->source_stream)
                ->where('currency', $batch->currency)->whereHas('approval')
                ->where('receipts_from', '<=', $batch->collected_from)->where('valid_until', '>=', $batch->collected_until)->get();
            if ($routes->isEmpty()) {
                return null;
            }
            if ($routes->count() !== 1) {
                throw ValidationException::withMessages(['routing' => 'Receipt matches conflicting approved routes; no budget is selected automatically.']);
            }
            $route = $routes->firstOrFail();
            $approval = $route->approval;
            if ($approval === null || ! $approval->hasValidEvidence($route)) {
                throw ValidationException::withMessages(['routing' => 'Approved receipt context failed integrity checks.']);
            }

            return ReceiptPlanTrigger::query()->create(['organization_id' => $institution->id,
                'receipt_budget_route_approval_id' => $approval->id, 'collection_batch_review_id' => $review->id,
                'review_digest' => $review->review_digest, 'approval_digest' => $approval->approval_digest]);
        }, 3);
    }

    /** Create one immutable successor; retries return the same snapshot without rebasing its figures. */
    public function process(CollectionBatchReview $review): ?BudgetSnapshot
    {
        $trigger = $this->record($review);
        if (! $trigger instanceof ReceiptPlanTrigger) {
            return null;
        }

        return DB::transaction(function () use ($trigger): ?BudgetSnapshot {
            Organization::query()->whereKey($trigger->organization_id)->lockForUpdate()->firstOrFail();
            $trigger = ReceiptPlanTrigger::query()->whereKey($trigger->id)->lockForUpdate()->firstOrFail();
            if ($trigger->state === 'completed') {
                $successor = BudgetSnapshot::query()->findOrFail($trigger->budget_snapshot_id);
                if (! $successor->hasValidSnapshot()) {
                    throw ValidationException::withMessages(['routing' => 'Recorded successor evidence changed; history is never rebuilt.']);
                }

                return $successor;
            }
            if ($trigger->state !== 'queued') {
                return null;
            }

            try {
                $snapshot = $this->captureSuccessor($trigger);
            } catch (ValidationException $exception) {
                $trigger->update(['state' => 'blocked', 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]);

                return null;
            }
            $trigger->update(['state' => 'completed', 'predecessor_snapshot_id' => $snapshot->snapshot['receipt_routing']['predecessor_snapshot_id'],
                'budget_snapshot_id' => $snapshot->id, 'last_error' => null]);
            activity('finance')->performedOn($trigger)->event('receipt_successor_plan_captured')
                ->withProperties(['budget_snapshot_id' => $snapshot->id, 'route_approval_id' => $trigger->receipt_budget_route_approval_id,
                    'collection_review_id' => $trigger->collection_batch_review_id, 'can_execute' => false, 'service_capability' => 'receipt_successor_planning'])
                ->log('Approved receipt routing captured a bounded successor; plan review and payment authority remain separate');

            return $snapshot;
        }, 3);
    }

    /** @throws ValidationException */
    private function captureSuccessor(ReceiptPlanTrigger $trigger): BudgetSnapshot
    {
        DB::beginTransaction();
        try {
            $snapshot = $this->buildSuccessor($trigger);
            DB::commit();

            return $snapshot;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /** @throws ValidationException */
    private function buildSuccessor(ReceiptPlanTrigger $trigger): BudgetSnapshot
    {
        $approval = ReceiptBudgetRouteApproval::query()->where('organization_id', $trigger->organization_id)
            ->findOrFail($trigger->receipt_budget_route_approval_id);
        $route = $approval->route;
        $review = CollectionBatchReview::query()->where('organization_id', $trigger->organization_id)->findOrFail($trigger->collection_batch_review_id);
        $batch = CollectionBatch::query()->where('organization_id', $trigger->organization_id)->findOrFail($review->collection_batch_id);
        if (! $approval->hasValidEvidence($route) || $approval->approval_digest !== $trigger->approval_digest
            || $review->review_digest !== $trigger->review_digest || ! $review->hasValidEvidence($batch) || $review->decision !== 'approve_receipts'
            || ! $batch->isMovementSourced() || $route->valid_until->lte(now()) || $batch->collected_until->isFuture()
            || $batch->source_stream !== $route->source_stream || $batch->currency !== $route->currency
            || $batch->collected_from->lt($route->receipts_from) || $batch->collected_until->gt($route->valid_until)) {
            throw ValidationException::withMessages(['routing' => 'Current intact route, independent receipt review and exact source coverage required.']);
        }
        $base = $route->budgetSnapshot;
        $this->planner->forBackground($base);
        $previous = ReceiptPlanTrigger::query()->where('receipt_budget_route_approval_id', $approval->id)->where('state', 'completed')
            ->orderByDesc('budget_snapshot_id')->first();
        $predecessor = $previous === null ? $base : BudgetSnapshot::query()->findOrFail($previous->budget_snapshot_id);
        $this->planner->forBackground($predecessor);
        if ($predecessor->snapshot['bills'] !== $base->snapshot['bills']) {
            throw ValidationException::withMessages(['routing' => 'Successor cannot widen or replace the approved closed bill set.']);
        }
        $invoiceIds = InvoiceVersion::query()->whereIn('id', array_column($base->snapshot['bills'], 'id'))->pluck('invoice_id');
        if (PaymentIntent::query()->where('organization_id', $route->organization_id)->whereIn('invoice_id', $invoiceIds)
            ->where(fn ($query) => $query->whereHas('authorization')->orWhereHas('reservation'))->exists()
            || PaymentReservation::query()->where('organization_id', $route->organization_id)->whereHas('intent',
                fn ($query) => $query->where('budget_id', $base->budget_id))->exists()) {
            throw ValidationException::withMessages(['routing' => 'Existing payment work changes commitments; staff must capture fresh context before another automatic plan.']);
        }
        if (BudgetSnapshot::query()->where('organization_id', $route->organization_id)->where('budget_id', $base->budget_id)
            ->where('id', '>', $predecessor->id)->exists()) {
            throw ValidationException::withMessages(['routing' => 'Newer budget context exists outside this route; never choose it or ignore it automatically.']);
        }
        $ids = array_column($predecessor->snapshot['collections'], 'review_id');
        if (in_array($review->id, $ids, true) || count($ids) >= 100
            || $review->budgetSnapshots()->exists()) {
            throw ValidationException::withMessages(['routing' => 'Receipt already counted, routed elsewhere, or reviewed collection bound exceeded.']);
        }
        $ids[] = $review->id;
        sort($ids, SORT_NUMERIC);
        $content = $predecessor->snapshot;
        $content['capture_key'] = Uuid::uuid5(Uuid::NAMESPACE_URL, 'eduflow:receipt-plan:'.$approval->approval_digest.':'.$review->review_digest)->toString();
        $content['prepared_by'] = $route->prepared_by;
        $content['as_of'] = now()->utc()->startOfSecond()->toIso8601String();
        $content['valid_until'] = $route->valid_until->utc()->toIso8601String();
        $content['amounts']['realized_receipts'] = (string) BigInteger::of($content['amounts']['realized_receipts'])->plus($batch->received_minor_units);
        $content['amounts']['restricted_cash'] = (string) BigInteger::of($content['amounts']['restricted_cash'])->plus($batch->restricted_minor_units);
        if (BigInteger::of($content['amounts']['realized_receipts'])->isGreaterThan(PHP_INT_MAX)
            || BigInteger::of($content['amounts']['restricted_cash'])->isGreaterThan(PHP_INT_MAX)) {
            throw ValidationException::withMessages(['routing' => 'Cumulative exact receipt amounts exceed supported integer bounds; staff review required.']);
        }
        $content['collections'] = $this->collections->capture($route->organization_id, $route->currency, $ids, $content['as_of'],
            $content['amounts']['realized_receipts'], $content['amounts']['restricted_cash']);
        $movement = BigInteger::zero();
        foreach ($content['collections'] as $entry) {
            $movement = $movement->plus($entry['movement_receipt_minor_units']);
        }
        $content['movement_receipts_minor_units'] = (string) $movement;
        $content['receipt_routing'] = ['schema_version' => 1, 'trigger_id' => $trigger->id, 'route_id' => $route->id,
            'approval_id' => $approval->id, 'approval_digest' => $approval->approval_digest,
            'predecessor_snapshot_id' => $predecessor->id, 'predecessor_digest' => $predecessor->snapshot_digest,
            'collection_review_id' => $review->id, 'review_digest' => $review->review_digest,
            'service_capability' => 'receipt_successor_planning', 'can_execute' => false];
        $successor = BudgetSnapshot::query()->create(['capture_key' => $content['capture_key'], 'organization_id' => $route->organization_id,
            'budget_id' => $base->budget_id, 'prepared_by' => $route->prepared_by, 'currency' => $route->currency,
            'snapshot' => $content, 'snapshot_digest' => PaymentIntent::digest($content)]);
        $successor->collectionReviews()->attach($ids);
        $this->collections->requireBound($successor);
        $this->planner->forBackground($successor);

        return $successor;
    }
}
