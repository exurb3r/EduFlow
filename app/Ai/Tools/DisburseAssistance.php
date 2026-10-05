<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Actions\EvaluateAssistancePolicy;
use App\DTOs\PolicyEvaluationResult;
use App\Enums\AgentDecisionType;
use App\Enums\AssistanceStatus;
use App\Enums\TransactionType;
use App\Models\AgentDecision;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Wallet;
use App\Services\CircleWalletService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * The seam where model intent meets policy.
 *
 * This is the only route from a model to a disbursement, and it cannot bypass
 * the deterministic engine. `needsApproval()` runs the real policy action
 * against the real wallet, fund and policy version; `handle()` runs it again
 * and refuses if anything changed. The model chooses *what to propose*; PHP
 * decides *whether a human signs*.
 *
 * Re-evaluating rather than trusting the earlier verdict matters: the model may
 * propose different arguments between the pause and the resume, so the check
 * has to be current at execution time, not from whenever the run paused.
 */
class DisburseAssistance implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function __construct(
        private readonly EvaluateAssistancePolicy $policy,
        private readonly CircleWalletService $wallets,
        private readonly Organization $organization,
        private readonly AssistanceFund $fund,
        private readonly AssistancePolicyVersion $policyVersion,
    ) {}

    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Propose an emergency assistance disbursement for a student who has an open request.

        Approval is not yours to grant. A deterministic policy engine evaluates every
        proposal against the treasury reserve, the autonomous limit and the assistance fund;
        if it falls outside those bounds the run pauses for a human reviewer. Do not state
        or imply that a payment succeeded.
        TEXT;
    }

    /**
     * The deterministic policy engine, evaluated per call.
     *
     * Returning false lets the call execute now; returning an Approval pauses
     * the run so a person sees exactly why policy objected.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        $evaluation = $this->evaluate($request);

        if ($evaluation === null) {
            return Approval::required('The proposal could not be evaluated against policy.');
        }

        return $this->isSelfExecuting($evaluation['result'])
            ? false
            : Approval::required($evaluation['result']->reasoning);
    }

    public function handle(Request $request): Stringable|string
    {
        $assistanceRequest = $this->resolveRequest($request);

        if (! $assistanceRequest instanceof AssistanceRequest) {
            return 'Not disbursed. No assistance request with that id exists.';
        }

        // Re-evaluate at execution time rather than trusting needsApproval().
        $evaluation = $this->evaluate($request);

        if ($evaluation === null) {
            return 'Not disbursed. The proposal could not be evaluated against policy.';
        }

        $result = $evaluation['result'];

        if (! $this->isSelfExecuting($result)) {
            return 'Not disbursed. Policy requires human authorisation: '.$result->reasoning;
        }

        // A real destination is required. Never synthesise one.
        $recipient = $assistanceRequest->student?->payout_address;

        if ($recipient === null || $assistanceRequest->student->hasValidPayoutAddress() !== true) {
            return 'Not disbursed. No valid payout address is on file for this student.';
        }

        $wallet = $this->organization->primaryWallet();

        if (! $wallet instanceof Wallet) {
            return 'Not disbursed. The organization has no active wallet.';
        }

        try {
            $tx = $this->wallets->executePayment(
                wallet: $wallet,
                recipientAddress: $recipient,
                amount: $result->approvedAmount,
                type: TransactionType::STUDENT_ASSISTANCE,
                referenceType: AssistanceRequest::class,
                referenceId: $assistanceRequest->id,
                metadata: [
                    'ticket' => $assistanceRequest->ticket_number,
                    'policy' => $result->policyCode,
                    'quote_id' => $evaluation['quote']['quote_id'] ?? null,
                    'proposed_by' => 'settlement_operator',
                ]
            );

            $this->fund->recordDisbursement((int) round($result->approvedAmount * 1000000));

            $budget = $this->organization->budgets()->where('category', 'assistance')->first();

            $budget?->recordExpense($result->approvedAmount);

            $assistanceRequest->update([
                'status' => AssistanceStatus::RESOLVED,
                'resolved_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return 'Not disbursed. The transfer failed: '.$e->getMessage();
        }

        return sprintf(
            'Disbursed %s USDC to the student on %s (tx %s).',
            number_format($result->approvedAmount, 2),
            $tx->network ?? 'Arc',
            $tx->txHash ?? 'pending',
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'assistance_request_id' => $schema->integer()->required(),
            'reason' => $schema->string()->required(),
        ];
    }

    /**
     * Only an outright auto-approval may execute unattended.
     *
     * A partial approval still owes a human a decision on the remainder, so it
     * pauses rather than taking the smaller amount silently.
     */
    private function isSelfExecuting(PolicyEvaluationResult $result): bool
    {
        return $result->decision === AgentDecisionType::AUTO_APPROVE
            && ! $result->requiresHumanApproval;
    }

    /**
     * Run the real policy action, or null if that is impossible.
     *
     * @return array{result: PolicyEvaluationResult, decision: AgentDecision, quote: array<string, mixed>, fund: AssistanceFund}|null
     */
    private function evaluate(Request $request): ?array
    {
        $assistanceRequest = $this->resolveRequest($request);

        if (! $assistanceRequest instanceof AssistanceRequest) {
            return null;
        }

        try {
            $out = $this->policy->handle(
                $assistanceRequest,
                $this->fund,
                $this->policyVersion,
            );
        } catch (Throwable $e) {
            report($e);

            // Fail closed: an unevaluable proposal must never execute.
            return null;
        }

        return $out;
    }

    private function resolveRequest(Request $request): ?AssistanceRequest
    {
        $id = $request['assistance_request_id'] ?? null;

        if (! is_int($id) && (! is_string($id) || ! ctype_digit($id))) {
            return null;
        }

        return AssistanceRequest::find((int) $id);
    }
}
