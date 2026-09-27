<?php

declare(strict_types=1);

namespace App\Agents;

use App\Enums\AgentDecisionType;
use App\Enums\AssistanceStatus;
use App\Enums\TransactionType;
use App\Models\AgentDecision;
use App\Models\Approval;
use App\Models\AssistanceRequest;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Services\CircleWalletService;
use App\Services\FinancialPolicyEngine;
use App\Services\TreasuryForecastService;
use InvalidArgumentException;

class EduFlowAgent
{
    public function __construct(
        protected FinancialPolicyEngine $policyEngine,
        protected TreasuryForecastService $forecastService,
        protected CircleWalletService $circleService,
    ) {}

    /**
     * Run the complete autonomous cycle for an organization.
     *
     * OBSERVE -> FORECAST -> ANALYZE -> CHECK POLICIES -> DECIDE -> EXECUTE/ESCALATE -> AUDIT
     *
     * @return array<string, mixed>
     */
    public function runAutonomousCycle(Organization $org): array
    {
        $wallet = $org->primaryWallet();
        if (! $wallet) {
            throw new InvalidArgumentException("Organization {$org->name} does not have an active Circle wallet.");
        }

        // 1. FORECAST 30-Day Liquidity
        $forecast = $this->forecastService->forecast($org, $wallet, 30);

        $processedInvoices = [];
        $processedAssistance = [];
        $totalDisbursed = 0.00;
        $autoPaidCount = 0;
        $escalatedCount = 0;
        $heldCount = 0;
        $rejectedCount = 0;

        // 2. OBSERVE & EVALUATE INVOICES
        $pendingInvoices = Invoice::where('organization_id', $org->id)
            ->where('status', 'pending')
            ->with(['vendor', 'budget'])
            ->orderBy('due_date')
            ->get();

        foreach ($pendingInvoices as $invoice) {
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

            switch ($policyResult->decision) {
                case AgentDecisionType::AUTO_APPROVE:
                    // Execute USDC transfer on Arc
                    $tx = $this->circleService->executePayment(
                        wallet: $wallet,
                        recipientAddress: $invoice->vendor->wallet_address,
                        amount: $invoice->amount,
                        type: TransactionType::VENDOR_PAYMENT,
                        referenceType: Invoice::class,
                        referenceId: $invoice->id,
                        metadata: ['invoice_reference' => $invoice->reference]
                    );

                    // Update budget if assigned
                    if ($invoice->budget) {
                        $invoice->budget->recordExpense($invoice->amount);
                    }

                    $invoice->update(['status' => 'auto_paid']);
                    $decision->update(['status' => 'executed']);

                    $totalDisbursed += $invoice->amount;
                    $autoPaidCount++;
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

            $processedInvoices[] = [
                'reference' => $invoice->reference,
                'decision' => $policyResult->decision->value,
                'reason' => $policyResult->reasoning,
            ];
        }

        // 3. OBSERVE & EVALUATE STUDENT ASSISTANCE
        $aidBudget = Budget::where('organization_id', $org->id)
            ->where('category', 'assistance')
            ->first();

        $pendingAid = AssistanceRequest::where('status', AssistanceStatus::PENDING)->get();

        foreach ($pendingAid as $aidRequest) {
            // Standard student emergency assistance is budgeted at 100 USDC auto-allowance
            $requestedAmt = 100.00;
            $aidResult = $this->policyEngine->evaluateStudentAssistance($requestedAmt, $org, $wallet, $aidBudget);

            $decision = AgentDecision::create([
                'organization_id' => $org->id,
                'action_type' => 'student_assistance',
                'reference_type' => AssistanceRequest::class,
                'reference_id' => $aidRequest->id,
                'input_snapshot' => [
                    'student_name' => $aidRequest->user->name,
                    'ticket' => $aidRequest->ticket_number,
                    'subject' => $aidRequest->subject,
                    'wallet_balance' => $wallet->balance,
                ],
                'reasoning_summary' => $aidResult->reasoning,
                'policy_checked' => $aidResult->policyCode,
                'decision' => $aidResult->decision,
                'requested_amount' => $requestedAmt,
                'approved_amount' => $aidResult->approvedAmount,
                'requires_approval' => $aidResult->requiresHumanApproval,
                'status' => 'pending',
            ]);

            if ($aidResult->decision === AgentDecisionType::AUTO_APPROVE || $aidResult->decision === AgentDecisionType::PARTIAL_APPROVAL) {
                $recipient = '0xstudent_'.substr(md5((string) $aidRequest->user_id), 0, 16);

                $tx = $this->circleService->executePayment(
                    wallet: $wallet,
                    recipientAddress: $recipient,
                    amount: $aidResult->approvedAmount,
                    type: TransactionType::STUDENT_ASSISTANCE,
                    referenceType: AssistanceRequest::class,
                    referenceId: $aidRequest->id,
                    metadata: ['ticket' => $aidRequest->ticket_number]
                );

                if ($aidBudget) {
                    $aidBudget->recordExpense($aidResult->approvedAmount);
                }

                $aidRequest->update([
                    'status' => AssistanceStatus::RESOLVED,
                    'admin_notes' => "EduFlow AI: {$aidResult->reasoning}. Disbursed {$aidResult->approvedAmount} USDC on Arc. Tx: {$tx->provider_tx_hash}",
                    'resolved_at' => now(),
                ]);

                $decision->update(['status' => 'executed']);
                $totalDisbursed += $aidResult->approvedAmount;
                $autoPaidCount++;
            } elseif ($aidResult->decision === AgentDecisionType::HOLD) {
                $aidRequest->update([
                    'admin_notes' => "EduFlow AI: {$aidResult->reasoning}. Held to protect minimum reserve.",
                ]);
                $decision->update(['status' => 'held']);
                $heldCount++;
            }

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
                'auto_paid' => $autoPaidCount,
                'escalated' => $escalatedCount,
                'held' => $heldCount,
                'rejected' => $rejectedCount,
                'total_disbursed_usdc' => $totalDisbursed,
                'remaining_treasury' => $wallet->balance,
            ],
        ];
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
