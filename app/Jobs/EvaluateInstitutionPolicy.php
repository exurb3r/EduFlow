<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ProposeReceiptAllocation;
use App\Models\BudgetSnapshot;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\InstitutionPolicyPublication;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyEvaluations;
use App\Services\InstitutionPolicyInterpreter;
use App\Services\InstitutionPolicyWorkspace;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

class EvaluateInstitutionPolicy implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public int $evaluationId)
    {
        $this->afterCommit();
    }

    public function handle(InstallationInstitution $institutions, InstitutionPolicyWorkspace $policies,
        InstitutionPolicyEvaluations $evaluations, InstitutionPolicyInterpreter $interpreter,
        ProposeReceiptAllocation $allocation, AiProviderResolver $providers): void
    {
        $evaluation = InstitutionPolicyEvaluation::query()->where('organization_id', $institutions->require()->id)->findOrFail($this->evaluationId);
        if ($evaluation->state !== 'queued') {
            return;
        }
        $claimed = DB::table($evaluation->getTable())->where('id', $evaluation->id)->where('state', 'queued')
            ->update(['state' => 'running', 'stage' => 'evidence_checks', 'started_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return;
        }
        $evaluation->refresh();

        try {
            $evaluation->checkpoint('evidence_checks', 'Checking institution, source evidence and active policy revision.');
            $actor = User::query()->findOrFail($evaluation->requested_by);
            $publication = $this->assertEvidence($evaluation, $actor, $policies, $evaluations);
            $description = $interpreter->classificationProvider();
            $evaluation->update(['provider' => $description['name'], 'model' => $description['model']]);

            if ($evaluation->source_type === 'budget_snapshot') {
                $snapshot = BudgetSnapshot::query()->where('organization_id', $evaluation->organization_id)->findOrFail($evaluation->source_id);
                $plan = $allocation->handle($actor, $snapshot, $evaluation->request_key,
                    function (string $stage, string $message, int $completed, int $total) use ($evaluation): void {
                        $evaluation->checkpoint($stage, $message, ['completed_targets' => $completed, 'total_targets' => $total]);
                    }, fresh: true);
                $result = $plan->plan;
                $evaluation->update(['receipt_allocation_plan_id' => $plan->id]);
                $needsReview = in_array('hold', array_column($result['lines'], 'decision'), true);
            } else {
                $evaluation->checkpoint('classifying', 'Agent is matching recorded evidence to saved policy clauses.', ['total_targets' => 1]);
                $result = $interpreter->interpret($publication->version, 'classification', $evaluation->input['evidence']);
                $result += ['funds_reserved' => false, 'payment_approved' => false, 'actual_accounts_changed' => false,
                    'funding_context_bound' => false,
                    'next_step' => 'Classification only. Bind independently reviewed receipts to an intact budget snapshot before requesting allocation amounts.'];
                $needsReview = $result['needs_review'];
                $evaluation->checkpoint('citations_checked', 'Saved policy citations validated. Recorded transaction is not funding authority.', ['completed_targets' => 1]);
            }

            $this->assertEvidence($evaluation, $actor->fresh(), $policies, $evaluations);
            $evaluation->checkpoint('safety_checks', 'Policy and source still match. No receipt review, reservation or payment authority created.');
            $evaluation->checkpoint($needsReview ? 'needs_review' : 'completed', $needsReview
                ? 'Advisory result recorded with holds or questions for finance review. Nothing was paid.'
                : 'Advisory result ready for finance review. Nothing was paid.',
                ['state' => $needsReview ? 'needs_review' : 'completed', 'result' => $result,
                    'result_digest' => PaymentIntent::digest($result), 'completed_at' => now(), 'last_error' => null]);
        } catch (Throwable $exception) {
            report($exception);
            $evaluation->checkpoint('needs_review', 'Evaluation stopped. Evidence or service unavailable; no financial action occurred.',
                ['state' => 'needs_review', 'completed_at' => now(),
                    'last_error' => 'Evaluation unavailable or evidence changed. Refresh source and policy before requesting a new run.']);
        }
    }

    private function assertEvidence(InstitutionPolicyEvaluation $evaluation, User $actor, InstitutionPolicyWorkspace $policies,
        InstitutionPolicyEvaluations $evaluations): InstitutionPolicyPublication
    {
        Gate::forUser($actor)->authorize('create', InstitutionPolicyEvaluation::class);
        $publication = $policies->active();
        if (! $evaluation->hasValidInput() || ! $publication instanceof InstitutionPolicyPublication
            || $publication->publication_digest !== $evaluation->publication_digest
            || $publication->institution_policy_version_id !== $evaluation->institution_policy_version_id) {
            throw new RuntimeException('Active policy or request integrity changed.');
        }
        $source = $evaluations->source($actor, $evaluation->source_type, $evaluation->source_id);
        if (! hash_equals($evaluation->source_digest, $source['source_digest'])) {
            throw new RuntimeException('Source evidence changed before evaluation finished.');
        }

        return $publication;
    }

    public function failed(?Throwable $exception): void
    {
        $evaluation = InstitutionPolicyEvaluation::query()->find($this->evaluationId);
        if ($evaluation !== null && $evaluation->completed_at === null) {
            $evaluation->checkpoint('needs_review', 'Worker stopped. Request a fresh run; prior progress remains visible.',
                ['state' => 'needs_review', 'completed_at' => now(), 'last_error' => 'Evaluation worker stopped. No financial action occurred.']);
        }
        if ($exception instanceof Throwable) {
            report($exception);
        }
    }
}
