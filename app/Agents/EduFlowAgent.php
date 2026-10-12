<?php

declare(strict_types=1);

namespace App\Agents;

use App\Actions\ApproveEscalatedRequest;
use App\Actions\EvaluateAssistancePolicy;
use App\Enums\AgentDecisionType;
use App\Enums\AssistanceStatus;
use App\Enums\CurrencyCode;
use App\Enums\TransactionType;
use App\Models\AgentDecision;
use App\Models\Approval;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Services\CircleWalletService;
use App\Services\DecisionExplainer;
use App\Services\FinancialPolicyEngine;
use App\Services\TreasuryForecastService;
use Closure;
use InvalidArgumentException;

class EduFlowAgent
{
    public function __construct(
        protected FinancialPolicyEngine $policyEngine,
        protected TreasuryForecastService $forecastService,
        protected CircleWalletService $circleService,
        protected EvaluateAssistancePolicy $assistancePolicy,
        protected DecisionExplainer $explainer,
    ) {}

    /**
     * Run the complete autonomous cycle for an organization.
     *
     * OBSERVE -> FORECAST -> ANALYZE -> CHECK POLICIES -> DECIDE -> EXECUTE/ESCALATE -> AUDIT
     *
     * The request-local callback receives public execution facts and must not throw.
     *
     * @param  null|Closure(array{phase: string, title: string, summary: string, decision_id?: int, policy?: string, status?: string, reference?: string}): void  $onProgress
     * @return array<string, mixed>
     */
    public function runAutonomousCycle(Organization $org, ?Closure $onProgress = null): array
    {
        @set_time_limit(180);

        $wallet = $org->primaryWallet();
        if (! $wallet) {
            throw new InvalidArgumentException("Organization {$org->name} does not have an active Circle wallet.");
        }

        // 1. FORECAST 30-Day Liquidity
        $this->reportProgress($onProgress, 'forecast', 'Starting liquidity forecast',
            'Computing 30-day liquidity from the local ledger and scheduled obligations; on-chain funds are not verified.');
        $forecast = $this->forecastService->forecast($org, $wallet, 30);
        $this->reportProgress($onProgress, 'forecast', 'Liquidity forecast available',
            "Projected ledger balance: {$forecast->projectedBalance} USDC; minimum reserve: {$forecast->minimumReserve} USDC; health: {$forecast->healthStatus}. On-chain funds are not verified.");

        $processedInvoices = [];
        $processedAssistance = [];
        $totalDisbursed = 0.00;
        $autoPaidCount = 0;
        $requiresWorkflowCount = 0;
        $escalatedCount = 0;
        $heldCount = 0;
        $rejectedCount = 0;

        // 2. OBSERVE & EVALUATE INVOICES
        $pendingInvoices = Invoice::where('organization_id', $org->id)
            ->where('status', 'pending')
            ->with(['vendor', 'budget'])
            ->orderBy('due_date')
            ->get();

        $this->reportProgress($onProgress, 'observe', 'Pending invoices observed',
            "Found {$pendingInvoices->count()} pending invoices for policy evaluation.");

        foreach ($pendingInvoices as $invoice) {
            if ($invoice->hasExactVersion()) {
                $processedInvoices[] = ['reference' => $invoice->reference, 'decision' => 'exact_evidence_required',
                    'reason' => 'Versioned invoice is excluded from legacy payment execution; local payable state stays unchanged.'];

                $this->reportProgress($onProgress, 'policy', 'Invoice requires exact evidence',
                    'Versioned invoice is excluded from legacy payment execution; local payable state stays unchanged.',
                    reference: $invoice->reference, status: 'exact_evidence_required');

                continue;
            }
            $policyResult = $this->policyEngine->evaluateInvoice($invoice, $wallet);

            $decision = AgentDecision::create([
                'organization_id' => $org->id,
                'action_type' => 'pay_vendor',
                'reference_type' => Invoice::class,
                'reference_id' => $invoice->id,
                'input_snapshot' => [
                    'invoice_ref' => $invoice->reference,
                    'vendor_name' => $invoice->vendor->name,
                    'amount' => $invoice->amount,
                    'wallet_balance' => $wallet->balance,
                    'minimum_reserve' => $org->minimum_reserve,
                    'forecast_health' => $forecast->healthStatus,
                ],
                'reasoning_summary' => $policyResult->reasoning,
                'policy_checked' => $policyResult->policyCode,
                'decision' => $policyResult->decision,
                'requested_amount' => $policyResult->requestedAmount,
                'approved_amount' => $policyResult->approvedAmount,
                'requires_approval' => $policyResult->requiresHumanApproval,
                'status' => 'pending',
            ]);

            $this->reportProgress($onProgress, 'policy', 'Invoice policy result recorded',
                $policyResult->reasoning, $decision, $invoice->reference, $policyResult->decision->value);

            switch ($policyResult->decision) {
                case AgentDecisionType::AUTO_APPROVE:
                    // This agent may no longer move money. A policy engine
                    // returning AUTO_APPROVE records a *proposal*; it is not an
                    // instruction to pay. Execution belongs to the reviewed
                    // chain — a standing mandate admits an occurrence, and a
                    // human authorizes an individual payment — and this path
                    // bypasses every one of those gates.
                    $this->reportProgress($onProgress, 'execute', 'Payment initiation withheld',
                        'This agent may not initiate payment. The bill is routed to the authorized workflow for independent review, or to a standing mandate if one covers it.',
                        $decision, $invoice->reference, 'requires_authorized_workflow');

                    $decision->update(['status' => 'requires_authorized_workflow']);

                    $processedInvoices[] = ['reference' => $invoice->reference, 'decision' => 'requires_authorized_workflow',
                        'reason' => 'Payment initiation is withheld; the authorized workflow decides, not this agent.'];

                    $requiresWorkflowCount++;
                    break;

                case AgentDecisionType::ESCALATE:
                    $invoice->update(['status' => 'escalated']);
                    Approval::create([
                        'organization_id' => $org->id,
                        'agent_decision_id' => $decision->id,
                        'status' => 'pending',
                    ]);
                    $decision->update(['status' => 'escalated']);
                    $escalatedCount++;
                    break;

                case AgentDecisionType::HOLD:
                    $invoice->update(['status' => 'held']);
                    $decision->update(['status' => 'held']);
                    $heldCount++;
                    break;

                case AgentDecisionType::REJECT:
                    $invoice->update(['status' => 'rejected']);
                    $decision->update(['status' => 'rejected']);
                    $rejectedCount++;
                    break;

                default:
                    break;
            }

            $this->reportProgress($onProgress, 'outcome', 'Invoice outcome recorded',
                "Local invoice status: {$invoice->status}; decision status: {$decision->status}. Local status does not verify on-chain settlement.",
                $decision, $invoice->reference, $invoice->status);

            $processedInvoices[] = [
                'reference' => $invoice->reference,
                'decision' => $policyResult->decision->value,
                'reason' => $policyResult->reasoning,
            ];
        }

        // 3. OBSERVE & EVALUATE STUDENT ASSISTANCE
        // Deterministic integer policy with locked FX quote (PLAN Part 2):
        // request -> quote lock -> canonical USDC evaluation -> disburse or escalate.
        Budget::where('organization_id', $org->id)
            ->where('category', 'assistance')
            ->first();

        $fund = AssistanceFund::where('organization_id', $org->id)->first();
        $policy = AssistancePolicyVersion::active($org->id);

        $pendingAid = AssistanceRequest::whereIn('status', [
            AssistanceStatus::SUBMITTED->value,
            AssistanceStatus::PENDING->value,
        ])->with(['student.tuitionAccounts', 'user'])->get();

        $this->reportProgress($onProgress, 'observe', 'Pending assistance observed',
            "Found {$pendingAid->count()} pending assistance requests for policy evaluation.");

        foreach ($pendingAid as $aidRequest) {
            $requestedBase = (int) ($aidRequest->requested_amount ?? 0);

            $this->reportProgress($onProgress, 'policy', 'Checking assistance policies',
                'Checking fund and policy setup, enrollment, academic standing, attendance, outstanding tuition, caps, reserve protection, and payout address.',
                reference: $aidRequest->ticket_number, status: 'checking');

            if (! $fund || ! $policy instanceof AssistancePolicyVersion) {
                $decision = AgentDecision::create([
                    'organization_id' => $org->id,
                    'action_type' => 'student_assistance',
                    'reference_type' => AssistanceRequest::class,
                    'reference_id' => $aidRequest->id,
                    'input_snapshot' => [
                        'ticket' => $aidRequest->ticket_number,
                        'requested_base_units' => $requestedBase,
                        'wallet_balance' => $wallet->balance,
                    ],
                    'reasoning_summary' => 'Assistance fund or policy version is not configured. Escalated for setup.',
                    'policy_checked' => 'AID_SETUP_REQUIRED_V1',
                    'decision' => AgentDecisionType::ESCALATE,
                    'requested_amount' => round($requestedBase / 1000000, 2),
                    'approved_amount' => 0.00,
                    'requires_approval' => true,
                    'status' => 'escalated',
                ]);

                Approval::create([
                    'organization_id' => $org->id,
                    'agent_decision_id' => $decision->id,
                    'status' => 'pending',
                ]);

                $aidRequest->update(['admin_notes' => 'EduFlow AI: assistance fund or policy missing. Escalated for setup.']);
                $escalatedCount++;

                $processedAssistance[] = [
                    'ticket' => $aidRequest->ticket_number,
                    'decision' => AgentDecisionType::ESCALATE->value,
                ];

                $this->reportProgress($onProgress, 'outcome', 'Assistance setup escalated',
                    $decision->reasoning_summary, $decision, $aidRequest->ticket_number, $decision->status);

                continue;
            }

            $out = $this->assistancePolicy->handle($aidRequest, $fund, $policy, CurrencyCode::PHP);
            $aidResult = $out['result'];
            $decision = $out['decision'];
            $explanation = $this->explainer->explain($aidResult, $requestedBase, CurrencyCode::PHP);

            $checkSummaries = [];
            foreach ($aidResult->checks as $check => $passed) {
                $checkSummaries[] = $check.': '.($passed ? 'passed' : 'failed');
            }
            $this->reportProgress($onProgress, 'policy', 'Assistance checks recorded',
                implode('; ', $checkSummaries).'.', $decision, $aidRequest->ticket_number, 'checked');
            $this->reportProgress($onProgress, 'policy', 'Assistance policy result recorded',
                $aidResult->reasoning, $decision, $aidRequest->ticket_number, $aidResult->decision->value);

            // A payment needs a real destination. The agent must never invent
            // one: an address the recipient does not control is either rejected
            // by the Circle CLI or, worse, accepted and irretrievable.
            $recipient = $aidRequest->student?->payout_address;

            if (in_array($aidResult->decision, [AgentDecisionType::AUTO_APPROVE, AgentDecisionType::PARTIAL_APPROVAL], true)
                && ($recipient === null || $aidRequest->student?->hasValidPayoutAddress() !== true)) {
                $decision->update([
                    'status' => 'escalated',
                    'decision' => AgentDecisionType::ESCALATE,
                    'approved_amount' => 0.00,
                    'requires_approval' => true,
                    'policy_checked' => 'STUDENT_PAYOUT_ADDRESS_MISSING_V1',
                    'reasoning_summary' => 'Approved amount withheld: the student has no valid payout address on file. '
                        .'Record a 0x address for this student before any assistance can be disbursed.',
                ]);

                Approval::create([
                    'organization_id' => $org->id,
                    'agent_decision_id' => $decision->id,
                    'status' => 'pending',
                ]);

                $aidRequest->update([
                    'status' => AssistanceStatus::IN_PROGRESS,
                    'assigned_to' => null,
                    'admin_notes' => 'EduFlow AI: no valid payout address on file for this student. '
                        .'Escalated so Finance can record one before disbursing.',
                ]);

                $escalatedCount++;

                $processedAssistance[] = [
                    'ticket' => $aidRequest->ticket_number,
                    'decision' => AgentDecisionType::ESCALATE->value,
                ];

                $this->reportProgress($onProgress, 'outcome', 'Assistance payout address escalated',
                    $decision->reasoning_summary, $decision, $aidRequest->ticket_number, $decision->status);

                continue;
            }

            if ($aidResult->decision === AgentDecisionType::AUTO_APPROVE || $aidResult->decision === AgentDecisionType::PARTIAL_APPROVAL) {
                // Same rule as the vendor path: this agent proposes, it does
                // not pay. A payout auto-disbursed by a policy engine is money
                // nobody authorized, sent to a wallet whose ownership this
                // agent never verified.
                $this->reportProgress($onProgress, 'execute', 'Payment initiation withheld',
                    'This agent may not initiate payment. Assistance disbursement requires an independently authorized workflow.',
                    $decision, $aidRequest->ticket_number, 'requires_authorized_workflow');

                $requiresWorkflowCount++;

                if ($aidResult->decision === AgentDecisionType::PARTIAL_APPROVAL) {
                    // A partial approval is genuinely escalated: the remainder
                    // still needs a human. Its decision keeps the escalated
                    // status so the approve/reject actions stay visible, which
                    // is the only route by which this money can ever move.
                    Approval::create([
                        'organization_id' => $org->id,
                        'agent_decision_id' => $decision->id,
                        'status' => 'pending',
                    ]);

                    $aidRequest->update([
                        'status' => AssistanceStatus::IN_PROGRESS,
                        'admin_notes' => "EduFlow AI: {$explanation}. Payment initiation withheld; the escalated remainder requires officer review.",
                    ]);

                    $escalatedCount++;
                } else {
                    $decision->update(['status' => 'requires_authorized_workflow']);

                    $aidRequest->update([
                        'status' => AssistanceStatus::RESOLVED,
                        'admin_notes' => "EduFlow AI: {$aidResult->reasoning}. Payment initiation withheld; requires authorized workflow review.",
                        'resolved_at' => now(),
                    ]);
                }
            } elseif ($aidResult->decision === AgentDecisionType::HOLD) {
                $aidRequest->update([
                    'admin_notes' => "EduFlow AI: {$aidResult->reasoning}. Held to protect minimum reserve.",
                ]);
                $decision->update(['status' => 'held']);
                $heldCount++;
            } elseif ($aidResult->decision === AgentDecisionType::REJECT) {
                $aidRequest->update([
                    'status' => AssistanceStatus::CLOSED,
                    'admin_notes' => "EduFlow AI: {$explanation}",
                    'resolved_at' => now(),
                ]);
                $decision->update(['status' => 'rejected']);
                $rejectedCount++;
            } else {
                Approval::create([
                    'organization_id' => $org->id,
                    'agent_decision_id' => $decision->id,
                    'status' => 'pending',
                ]);

                $aidRequest->update([
                    'status' => AssistanceStatus::IN_PROGRESS,
                    'admin_notes' => "EduFlow AI: {$explanation}",
                ]);

                $escalatedCount++;
            }

            $assistanceStatus = $aidRequest->status instanceof AssistanceStatus
                ? $aidRequest->status->value
                : (string) $aidRequest->status;
            $this->reportProgress($onProgress, 'outcome', 'Assistance outcome recorded',
                "Local assistance status: {$assistanceStatus}; decision status: {$decision->status}. Local status does not verify on-chain settlement.",
                $decision, $aidRequest->ticket_number, $assistanceStatus);

            $processedAssistance[] = [
                'ticket' => $aidRequest->ticket_number,
                'decision' => $aidResult->decision->value,
            ];
        }

        return [
            'forecast' => $forecast->toArray(),
            'processed_invoices' => $processedInvoices,
            'processed_assistance' => $processedAssistance,
            'stats' => [
                // Always zero. This agent can no longer initiate a payment, so
                // "auto_paid" is kept as a reported zero rather than quietly
                // removed: a dashboard reading 0 here is a fact about the
                // system, and its absence would be ambiguous.
                'auto_paid' => $autoPaidCount,
                // Proposals a policy engine approved that this agent refuses to
                // act on. These are the ones the authorized workflow must take.
                'requires_authorized_workflow' => $requiresWorkflowCount,
                'escalated' => $escalatedCount,
                'held' => $heldCount,
                'rejected' => $rejectedCount,
                'total_disbursed_usdc' => $totalDisbursed,
                'remaining_treasury' => $wallet->balance,
            ],
        ];
    }

    /**
     * @param  null|Closure(array{phase: string, title: string, summary: string, decision_id?: int, policy?: string, status?: string, reference?: string}): void  $onProgress
     */
    private function reportProgress(
        ?Closure $onProgress,
        string $phase,
        string $title,
        string $summary,
        ?AgentDecision $decision = null,
        ?string $reference = null,
        ?string $status = null,
    ): void {
        if (! $onProgress instanceof Closure) {
            return;
        }

        $event = ['phase' => $phase, 'title' => $title, 'summary' => $summary];

        if ($decision instanceof AgentDecision) {
            $event['decision_id'] = $decision->id;
            $event['policy'] = $decision->policy_checked;
        }

        if ($reference !== null) {
            $event['reference'] = $reference;
        }

        if ($status !== null) {
            $event['status'] = $status;
        }

        $onProgress($event);
    }

    /**
     * Human authorization workflow for escalated decisions.
     */
    public function approveEscalation(Approval $approval, User $approver, ?string $comment = null): bool
    {
        $decision = $approval->agentDecision;
        $org = $approval->organization;
        $wallet = $org->primaryWallet();

        if (! $wallet) {
            throw new InvalidArgumentException('Primary wallet missing.');
        }

        // If the reference is an invoice
        if ($decision->reference_type === Invoice::class && $decision->reference_id) {
            $invoice = Invoice::find($decision->reference_id);
            if (! $invoice) {
                return false;
            }

            $tx = $this->circleService->executePayment(
                wallet: $wallet,
                recipientAddress: $invoice->vendor->wallet_address,
                amount: $invoice->amount,
                type: TransactionType::VENDOR_PAYMENT,
                referenceType: Invoice::class,
                referenceId: $invoice->id,
                metadata: ['approved_by' => $approver->name, 'human_override' => true]
            );

            if ($invoice->budget) {
                $invoice->budget->recordExpense($invoice->amount);
            }

            $invoice->update(['status' => 'paid']);

            $approval->update([
                'approver_id' => $approver->id,
                'status' => 'approved',
                'comment' => $comment ?? 'Approved via Finance Officer review override.',
                'approved_at' => now(),
            ]);

            $decision->update([
                'status' => 'executed',
                'approved_amount' => $invoice->amount,
            ]);

            return true;
        }

        // If the reference is a student assistance request
        if ($decision->reference_type === AssistanceRequest::class && $decision->reference_id) {
            $aid = AssistanceRequest::find($decision->reference_id);
            if (! $aid) {
                return false;
            }

            $fund = AssistanceFund::where('organization_id', $org->id)->first();
            if (! $fund) {
                return false;
            }

            app(ApproveEscalatedRequest::class)->handle(
                request: $aid,
                decision: $decision,
                approver: $approver,
                fund: $fund,
                comment: $comment,
            );

            return true;
        }

        return false;
    }

    /**
     * Human rejection of an escalated decision.
     */
    public function rejectEscalation(Approval $approval, User $approver, string $reason): bool
    {
        $decision = $approval->agentDecision;

        if ($decision->reference_type === Invoice::class && $decision->reference_id) {
            $invoice = Invoice::find($decision->reference_id);
            $invoice?->update(['status' => 'rejected']);
        }

        if ($decision->reference_type === AssistanceRequest::class && $decision->reference_id) {
            $aid = AssistanceRequest::find($decision->reference_id);
            $aid?->update([
                'status' => AssistanceStatus::CLOSED,
                'admin_notes' => trim(($aid->admin_notes ?? '')."\nEscalated remainder rejected by {$approver->name}: {$reason}"),
                'resolved_at' => now(),
            ]);
        }

        $approval->update([
            'approver_id' => $approver->id,
            'status' => 'rejected',
            'comment' => $reason,
            'approved_at' => now(),
        ]);

        $decision->update(['status' => 'rejected']);

        return true;
    }
}
