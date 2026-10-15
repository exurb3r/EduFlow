<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FinanceWorkflowRun;
use App\Services\FinanceReviewAlerts;
use App\Services\FinanceWorkflows;
use App\Services\InstitutionPolicyAdvice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessFinanceWorkflow implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [5, 30, 60];

    public function __construct(public int $runId)
    {
        $this->afterCommit();
    }

    public function handle(FinanceWorkflows $workflows, FinanceReviewAlerts $alerts): void
    {
        $workflows->process($this->runId);
        $alerts->deliver($this->runId);
        $run = FinanceWorkflowRun::query()->find($this->runId);
        if ($run !== null) {
            try {
                app(InstitutionPolicyAdvice::class)->forPlan($run);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        /** @var FinanceWorkflowRun|null $run */
        $run = FinanceWorkflowRun::query()->find($this->runId);
        if ($run !== null && in_array($run->state, ['queued', 'running'], true)) {
            $run->update(['state' => 'failed', 'last_error' => 'Background planning failed after bounded retries; inspect application logs.', 'heartbeat_at' => now()]);
        }
    }
}
