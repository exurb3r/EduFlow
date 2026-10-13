<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\CollectionBatchReview;
use App\Models\FinanceWorkflowRun;
use App\Models\Organization;
use App\Models\PaymentIntent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Durable read-only work/outbox identity. Never calls legacy execution or impersonates staff. */
final readonly class FinanceWorkflows
{
    public function __construct(private InstallationInstitution $institutions, private DepartmentBudgetPlanner $planner,
        private ReceiptTriggeredPlanning $receiptPlans) {}

    public function budgetCaptured(BudgetSnapshot $snapshot): void
    {
        $this->record($snapshot->organization_id, 'budget_plan', $snapshot->id, null, $snapshot->snapshot_digest, 'budget:'.$snapshot->id);
    }

    public function collectionCaptured(CollectionBatch $batch): void
    {
        $this->record($batch->organization_id, 'collection_review', null, $batch->id, $batch->snapshot_digest, 'collection:'.$batch->id);
    }

    /** Requeue a receipt run once its independent review exists; this creates no plan. */
    public function collectionReviewed(CollectionBatchReview $review): void
    {
        if (! config('eduflow.background_finance.enabled', false)) {
            return;
        }
        $institution = $this->institutions->require();
        $batch = CollectionBatch::query()->where('organization_id', $institution->id)->whereKey($review->collection_batch_id)->firstOrFail();
        if (! $review->hasValidEvidence($batch)) {
            throw ValidationException::withMessages(['collection' => 'Collection review failed integrity checks.']);
        }
        $this->collectionCaptured($batch);
        $trigger = null;
        try {
            $trigger = $this->receiptPlans->record($review);
        } catch (ValidationException $exception) {
            report($exception);
        }
        /** @var FinanceWorkflowRun|null $run */
        $run = FinanceWorkflowRun::query()
            ->where('organization_id', $institution->id)->where('trigger_key', 'collection:'.$batch->id)
            ->lockForUpdate()->first();
        if ($run !== null && $run->hasValidSource()
            && ($run->state === 'waiting_for_review' || ($run->state === 'completed' && $trigger?->state === 'queued'))) {
            $run->update(['state' => 'queued', 'dispatched_at' => null, 'next_attempt_at' => null]);
        }
    }

    private function record(int $institutionId, string $kind, ?int $budgetId, ?int $collectionId, string $digest, string $key): void
    {
        if (! config('eduflow.background_finance.enabled', false)) {
            return;
        }
        if ($this->institutions->require()->id !== $institutionId) {
            throw ValidationException::withMessages(['workflow' => 'Background source does not belong to the installation institution.']);
        }
        DB::transaction(function () use ($institutionId, $kind, $budgetId, $collectionId, $digest, $key): void {
            Organization::query()->whereKey($institutionId)->lockForUpdate()->firstOrFail();
            FinanceWorkflowRun::query()->firstOrCreate(['trigger_key' => $key], ['organization_id' => $institutionId, 'kind' => $kind,
                'budget_snapshot_id' => $budgetId, 'collection_batch_id' => $collectionId, 'source_digest' => $digest]);
        }, 3);
    }

    public function process(int $id): void
    {
        if (! config('eduflow.background_finance.enabled', false)) {
            return;
        }
        $institution = $this->institutions->require();
        DB::transaction(function () use ($id, $institution): void {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            /** @var FinanceWorkflowRun $run */
            $run = FinanceWorkflowRun::query()->where('organization_id', $institution->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $run->hasValidSource()) {
                $run->update(['state' => 'blocked', 'last_error' => 'Workflow source identity or evidence changed; independent investigation required.', 'heartbeat_at' => Carbon::now()]);

                return;
            }
            if ($run->state === 'waiting_for_review' && $run->kind === 'budget_plan') {
                try {
                    if ($run->budgetSnapshot === null || ! $run->hasValidResult()
                        || $run->result_digest !== PaymentIntent::digest($this->planner->forBackground($run->budgetSnapshot))) {
                        throw ValidationException::withMessages(['plan' => 'Recorded proposal no longer matches current evidence.']);
                    }
                } catch (ValidationException) {
                    $run->update(['state' => 'blocked', 'last_error' => 'Proposal evidence changed or expired; capture a fresh plan.', 'heartbeat_at' => Carbon::now()]);
                }

                return;
            }
            if (! in_array($run->state, ['queued', 'running'], true)
                && ($run->kind !== 'collection_review' || $run->state !== 'waiting_for_review')) {
                return;
            }
            $run->state = 'running';
            $run->attempts++;
            $run->heartbeat_at = Carbon::now();
            $run->save();
            try {
                if ($run->kind === 'collection_review') {
                    /** @var CollectionBatch $batch */
                    $batch = CollectionBatch::query()->where('organization_id', $institution->id)->whereKey($run->collection_batch_id)->firstOrFail();
                    if (! $batch->hasValidSnapshot() || $batch->snapshot_digest !== $run->source_digest) {
                        throw ValidationException::withMessages(['collection' => 'Collection source changed; review is blocked.']);
                    }

                    /** @var CollectionBatchReview|null $review */
                    $review = CollectionBatchReview::query()->where('collection_batch_id', $batch->id)->first();
                    if ($review !== null && ! $review->hasValidEvidence($batch)) {
                        throw ValidationException::withMessages(['collection' => 'Collection review changed; investigation required.']);
                    }
                    $run->state = $review === null ? 'waiting_for_review' : 'completed';
                    if ($review !== null) {
                        $successor = $this->receiptPlans->process($review);
                        if ($successor instanceof BudgetSnapshot) {
                            $this->budgetCaptured($successor);
                        }
                    }
                    if ($run->result_digest !== null) {
                        if (! $run->hasValidResult()) {
                            throw ValidationException::withMessages(['collection' => 'Recorded collection result failed integrity checks.']);
                        }
                        $run->heartbeat_at = Carbon::now();
                        $run->next_attempt_at = null;
                        $run->last_error = null;
                        $run->save();

                        return;
                    }
                    $result = ['collection_batch_id' => $batch->id, 'source_digest' => $batch->snapshot_digest,
                        'received_minor_units' => (string) $batch->received_minor_units, 'currency' => $batch->currency,
                        'review_required' => $run->state === 'waiting_for_review', 'can_execute' => false];
                } else {
                    /** @var BudgetSnapshot $budget */
                    $budget = BudgetSnapshot::query()->where('organization_id', $institution->id)->whereKey($run->budget_snapshot_id)->firstOrFail();
                    if ($budget->snapshot_digest !== $run->source_digest) {
                        throw ValidationException::withMessages(['plan' => 'Budget source changed; planning is blocked.']);
                    }
                    $result = $this->planner->forBackground($budget);
                    $run->state = in_array('propose_human_review', array_column($result['bills'], 'decision'), true) ? 'waiting_for_review' : 'blocked';
                }
                $run->result = $result;
                $run->result_digest = PaymentIntent::digest($result);
                $run->last_error = $run->state === 'blocked' ? 'No bill fits reviewed allocation and realized cash; no payment authorized.' : null;
            } catch (ValidationException $exception) {
                $run->state = 'blocked';
                $run->last_error = mb_substr(implode(' ', array_merge(...array_values($exception->errors()))), 0, 1000);
            }
            $run->heartbeat_at = Carbon::now();
            $run->next_attempt_at = null;
            $run->save();
        }, 3);
    }
}
