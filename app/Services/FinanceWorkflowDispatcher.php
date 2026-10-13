<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ProcessFinanceWorkflow;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatch;
use App\Models\FinanceWorkflowRun;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final readonly class FinanceWorkflowDispatcher
{
    public function __construct(private InstallationInstitution $institutions, private FinanceWorkflows $workflows,
        private ReceiptTriggeredPlanning $receiptPlans) {}

    /** Claims one queued run per lease. Jobs may retry; the run identity stays unique. */
    public function dispatchRun(int $runId): bool
    {
        if (! config('eduflow.background_finance.enabled', false)) {
            return false;
        }
        $this->requireDurableQueue();
        $institution = $this->institutions->require();

        $claimed = FinanceWorkflowRun::query()
            ->where('organization_id', $institution->id)
            ->whereKey($runId)
            ->where('state', 'queued')
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('dispatched_at')
                ->orWhere('dispatched_at', '<=', now()->subMinutes(2)))
            ->update(['dispatched_at' => now(), 'next_attempt_at' => now()->addMinutes(2)]);

        if ($claimed !== 1) {
            return false;
        }

        $this->enqueueAfterCommit($runId);

        return true;
    }

    /**
     * Claim and dispatch the single run this source owns.
     *
     * Trigger keys are unique, so the first run matching a source is the only
     * one it will ever have. Looking it up by source rather than by id keeps
     * the caller from having to know whether the run already existed.
     */
    public function dispatchSource(string $triggerKey): bool
    {
        if (! config('eduflow.background_finance.enabled', false)) {
            return false;
        }
        $this->requireDurableQueue();
        $institution = $this->institutions->require();
        /** @var FinanceWorkflowRun|null $run */
        $run = FinanceWorkflowRun::query()->where('organization_id', $institution->id)->where('trigger_key', $triggerKey)->first();

        return $run instanceof FinanceWorkflowRun && $this->dispatchRun($run->id);
    }

    /** A failed wake must not turn a committed evidence capture into a failed request. */
    public function wakeAfterCommit(string $triggerKey): void
    {
        if (! config('eduflow.background_finance.enabled', false)) {
            return;
        }
        DB::afterCommit(function () use ($triggerKey): void {
            try {
                $this->dispatchSource($triggerKey);
            } catch (Throwable $exception) {
                report(new RuntimeException('Background finance wake failed; durable work retained for scheduler recovery.', previous: $exception));
                $institution = $this->institutions->current();
                if ($institution instanceof Organization) {
                    FinanceWorkflowRun::query()->where('organization_id', $institution->id)->where('trigger_key', $triggerKey)
                        ->where('state', 'queued')->update(['last_error' => 'Background queue unavailable or unsafe; durable work retained for operator attention and scheduler recovery.']);
                }
            }
        });
    }

    /** Retain the claimed run for bounded scheduler recovery if enqueueing fails. */
    private function enqueueAfterCommit(int $runId): void
    {
        DB::afterCommit(function () use ($runId): void {
            try {
                Bus::dispatch(new ProcessFinanceWorkflow($runId));
            } catch (Throwable $exception) {
                report($exception);
                FinanceWorkflowRun::query()->whereKey($runId)->where('state', 'queued')
                    ->update(['last_error' => 'Queue dispatch failed; durable work retained for scheduler recovery after the dispatch lease.']);
            }
        });
    }

    private function requireDurableQueue(): void
    {
        $connection = (string) config('eduflow.background_finance.queue_connection', 'database');
        $driver = config('queue.connections.'.$connection.'.driver');
        if (! in_array($driver, ['database', 'redis'], true) || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= 30) {
            throw ValidationException::withMessages(['queue' => 'Background finance requires a durable database/Redis queue with retry_after greater than 30 seconds.']);
        }
        if (! Schema::hasTable('finance_workflow_runs')) {
            throw ValidationException::withMessages(['database' => 'Finance workflow migrations must be applied by the operator before enablement.']);
        }
    }

    public function dispatch(): int
    {
        if (! config('eduflow.background_finance.enabled', false)) {
            return 0;
        }
        $this->requireDurableQueue();
        $institution = $this->institutions->require();
        foreach ($this->receiptPlans->pendingReviews() as $review) {
            try {
                $this->workflows->collectionReviewed($review);
            } catch (ValidationException $exception) {
                report($exception);
            }
        }
        /** @var Collection<int, BudgetSnapshot> $budgets */
        $budgets = BudgetSnapshot::query()->where('organization_id', $institution->id)->whereDoesntHave('workflowRuns')->orderBy('id')->limit(100)->get();
        $budgets->each(function (BudgetSnapshot $snapshot): void {
            if (in_array($snapshot->snapshot['schema_version'] ?? null, [2, 3], true)) {
                $this->workflows->budgetCaptured($snapshot);
            }
        });
        /** @var Collection<int, CollectionBatch> $batches */
        $batches = CollectionBatch::query()->where('organization_id', $institution->id)->whereDoesntHave('workflowRuns')->orderBy('id')->limit(100)->get();
        $batches->each(fn (CollectionBatch $batch) => $this->workflows->collectionCaptured($batch));
        $count = 0;
        /** @var Collection<int, FinanceWorkflowRun> $pending */
        $pending = FinanceWorkflowRun::query()->where('organization_id', $institution->id)->where('state', 'queued')
            ->where(function (Builder $query): void {
                $query->whereNull('dispatched_at')->orWhere('dispatched_at', '<=', now()->subMinutes(2));
            })->orderBy('id')->limit(100)->get();
        foreach ($pending as $run) {
            if ($this->dispatchRun($run->id)) {
                $count++;
            }
        }
        foreach (FinanceWorkflowRun::query()->where('organization_id', $institution->id)->whereIn('state', ['waiting_for_review', 'blocked', 'failed'])
            ->orderBy('id')->limit(100)->get() as $run) {
            if ($run->next_attempt_at === null || $run->next_attempt_at->lte(now())) {
                $claimed = FinanceWorkflowRun::query()->whereKey($run->id)
                    ->where(fn (Builder $query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                    ->update(['next_attempt_at' => now()->addMinutes(2)]);
                if ($claimed === 1) {
                    $this->enqueueAfterCommit($run->id);
                }
            }
        }

        return $count;
    }
}
