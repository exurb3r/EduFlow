<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FinanceWorkflowRun;
use App\Models\InstitutionPolicyAssessment;
use App\Models\InstitutionPolicyPublication;
use App\Models\InstitutionPolicyVersion;
use App\Models\InvoiceVersion;
use App\Models\PaymentIntent;
use App\Services\Ai\AiProviderResolver;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyInterpreter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssessInstitutionPolicy implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $assessmentId)
    {
        $this->onConnection((string) config('eduflow.background_finance.queue_connection', 'database'));
        $this->onQueue('policy-assessments');
        $this->afterCommit();
    }

    /**
     * Execute the job.
     */
    public function handle(InstitutionPolicyInterpreter $interpreter, InstallationInstitution $institutions, AiProviderResolver $providers): void
    {
        $assessment = InstitutionPolicyAssessment::query()->where('organization_id', $institutions->require()->id)->findOrFail($this->assessmentId);
        if ($assessment->state !== 'queued') {
            return;
        }
        $claimed = DB::table($assessment->getTable())->where('id', $assessment->id)->where('state', 'queued')->update(['state' => 'running']);
        if ($claimed !== 1) {
            return;
        }
        $assessment->refresh();
        try {
            $policy = InstitutionPolicyVersion::query()->where('organization_id', $assessment->organization_id)->findOrFail($assessment->institution_policy_version_id);
            if (! $policy->hasValidContent() || $policy->content_digest !== $assessment->policy_digest
                || ! hash_equals($assessment->input_digest, PaymentIntent::digest($assessment->input))) {
                throw new \RuntimeException('Policy or evidence integrity changed.');
            }
            if (isset($assessment->input['publication_digest'])) {
                $published = InstitutionPolicyPublication::query()->where('organization_id', $assessment->organization_id)->latest('id')->first();
                if (! $published instanceof InstitutionPolicyPublication || ! $published->hasValidHistory()
                    || $published->publication_digest !== $assessment->input['publication_digest'] || ! $published->hasActiveControls()) {
                    throw new \RuntimeException('Published policy changed before advisory assessment.');
                }
                $bill = InvoiceVersion::query()->where('organization_id', $assessment->organization_id)->find($assessment->input['invoice_version_id'] ?? null);
                $run = FinanceWorkflowRun::query()->where('organization_id', $assessment->organization_id)->find($assessment->input['workflow_run_id'] ?? null);
                if ($bill === null || $run === null || ! $bill->hasValidSnapshot() || ! $run->hasValidResult()
                    || $bill->snapshot_digest !== ($assessment->input['source_digest'] ?? null)
                    || ! in_array($bill->id, array_column($run->result['bills'] ?? [], 'invoice_version_id'), true)) {
                    throw new \RuntimeException('Advisory bill or plan evidence changed before assessment.');
                }
            }
            if ($assessment->kind === 'examples') {
                $rows = [];
                $deadline = hrtime(true) + 140_000_000_000;
                foreach ($policy->content['examples'] as $index => $example) {
                    $remaining = intdiv($deadline - hrtime(true), 1_000_000_000);
                    if ($remaining < 3) {
                        throw new \RuntimeException('Example assessment exceeded bounded worker budget. No partial test result accepted.');
                    }
                    $answer = $interpreter->interpret($policy, 'classification', ['description' => $example['description']], $remaining);
                    $actual = $answer['category_id'] ?? 'needs_review';
                    $rows[] = ['index' => $index, 'expected' => $example['category_id'], 'actual' => $actual,
                        'passed' => $actual === $example['category_id'] && ($actual === 'needs_review' || ! $answer['needs_review']), 'assessment' => $answer];
                }
                $result = ['examples' => $rows, 'all_passed' => $rows !== [] && ! in_array(false, array_column($rows, 'passed'), true),
                    'advisory_only' => true, 'can_execute' => false, 'policy_digest' => $policy->content_digest,
                    'policy_version_id' => $policy->id];
            } else {
                $result = $interpreter->interpret($policy, $assessment->kind, $assessment->input);
            }
            $description = $assessment->kind === 'authoring' ? $providers->describe() : $interpreter->classificationProvider();
            $assessment->update(['state' => 'completed', 'result' => $result, 'result_digest' => PaymentIntent::digest($result),
                'provider' => $description['name'], 'model' => $description['model'], 'completed_at' => now(), 'last_error' => null]);
        } catch (Throwable $exception) {
            report($exception);
            $assessment->update(['state' => 'needs_review', 'last_error' => 'Interpretation unavailable or rejected. No classification or financial action applied.', 'completed_at' => now()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $assessment = InstitutionPolicyAssessment::query()->find($this->assessmentId);
        if ($assessment !== null && $assessment->completed_at === null) {
            $assessment->update(['state' => 'needs_review', 'last_error' => 'Assessment worker stopped. Request a new assessment; no financial action occurred.', 'completed_at' => now()]);
        }
        if ($exception instanceof Throwable) {
            report($exception);
        }
    }
}
