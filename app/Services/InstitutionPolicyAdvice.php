<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\AssessInstitutionPolicy;
use App\Models\FinancePolicyActivation;
use App\Models\FinanceWorkflowRun;
use App\Models\InstitutionPolicyAssessment;
use App\Models\InstitutionPolicyPublication;
use App\Models\InvoiceVersion;
use App\Models\PaymentIntent;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Throwable;

/** Published-policy advice is separate from bounded financial authorization and never feeds a signer. */
final readonly class InstitutionPolicyAdvice
{
    public function __construct(private InstitutionPolicyWorkspace $workspace) {}

    public function forPlan(FinanceWorkflowRun $run): void
    {
        if (! config('eduflow.institution_policy.advice_enabled', false) || $run->kind !== 'budget_plan' || ! $run->hasValidResult()) {
            return;
        }
        $publication = $this->workspace->current();
        if (! $publication instanceof InstitutionPolicyPublication || $publication->organization_id !== $run->organization_id
            || FinancePolicyActivation::current($run->organization_id)?->id !== $publication->finance_policy_activation_id) {
            return;
        }
        $version = $publication->version;
        $bills = InvoiceVersion::query()->where('organization_id', $run->organization_id)
            ->whereIn('id', array_column($run->result['bills'], 'invoice_version_id'))->get();
        foreach ($bills as $bill) {
            $input = ['description' => 'Existing approved bill source: '.json_encode($bill->snapshot['document'], JSON_THROW_ON_ERROR),
                'workflow_run_id' => $run->id, 'invoice_version_id' => $bill->id, 'source_digest' => $bill->snapshot_digest,
                'publication_digest' => $publication->publication_digest];
            $assessment = InstitutionPolicyAssessment::query()->firstOrCreate(['request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL,
                'eduflow:policy-advice:'.$publication->publication_digest.':'.$run->id.':'.$bill->snapshot_digest)->toString()], [
                    'organization_id' => $run->organization_id, 'institution_policy_version_id' => $version->id,
                    'requested_by' => null, 'kind' => 'classification', 'input' => $input,
                    'policy_digest' => $version->content_digest, 'input_digest' => PaymentIntent::digest($input)]);
            if (! $assessment->wasRecentlyCreated) {
                continue;
            }
            $connection = (string) config('eduflow.background_finance.queue_connection', 'database');
            try {
                if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)
                    || (int) config('queue.connections.'.$connection.'.retry_after', 0) <= 180) {
                    throw ValidationException::withMessages(['queue' => 'Policy advice requires durable queue with retry_after greater than 180 seconds.']);
                }
                Bus::dispatch(new AssessInstitutionPolicy($assessment->id));
            } catch (Throwable $exception) {
                report($exception);
                $assessment->update(['state' => 'needs_review', 'completed_at' => now(), 'last_error' => 'Advisory queue unavailable. Financial plan is unchanged.']);
            }
        }
    }
}
