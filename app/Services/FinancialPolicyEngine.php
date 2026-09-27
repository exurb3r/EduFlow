<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\PolicyEvaluationResult;
use App\Enums\AgentDecisionType;
use App\Models\Budget;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\Wallet;
use Illuminate\Support\Carbon;

class FinancialPolicyEngine
{
    /**
     * Evaluate a vendor invoice against deterministic organization policies.
     */
    public function evaluateInvoice(Invoice $invoice, Wallet $wallet): PolicyEvaluationResult
    {
        $org = $invoice->organization;
        $vendor = $invoice->vendor;
        $budget = $invoice->budget;
        $amount = (float) $invoice->amount;

        $checks = [
            'vendor_verified' => $vendor->isVerified(),
            'budget_available' => $budget === null || $budget->canAfford($amount),
            'reserve_protected' => ($wallet->balance - $amount) >= $org->minimum_reserve,
            'within_auto_limit' => $amount <= $org->max_auto_payment,
            'within_daily_cap' => $this->isWithinDailyCap($org, $amount),
        ];

        $violations = [];

        if (! $checks['vendor_verified']) {
            $violations[] = "Vendor '{$vendor->name}' is unverified ({$vendor->status}).";

            return new PolicyEvaluationResult(
                decision: AgentDecisionType::ESCALATE,
                requestedAmount: $amount,
                approvedAmount: 0.00,
                requiresHumanApproval: true,
                policyCode: 'VENDOR_COMPLIANCE_V1',
                reasoning: "Vendor '{$vendor->name}' is unverified. Payment must be reviewed by Finance Officer.",
                violations: $violations,
                checks: $checks,
            );
        }

        if (! $checks['budget_available']) {
            $remaining = $budget ? $budget->remaining_amount : 0.00;
            $violations[] = "Exceeds remaining allocated budget ({$remaining} USDC available).";

            return new PolicyEvaluationResult(
                decision: AgentDecisionType::REJECT,
                requestedAmount: $amount,
                approvedAmount: 0.00,
                requiresHumanApproval: false,
                policyCode: 'BUDGET_EXHAUSTION_V1',
                reasoning: "Budget '{$budget?->name}' does not have enough remaining funds to satisfy {$amount} USDC.",
                violations: $violations,
                checks: $checks,
            );
        }

        if (! $checks['reserve_protected']) {
            $postBalance = $wallet->balance - $amount;
            $violations[] = "Payment drops treasury to {$postBalance} USDC, breaching {$org->minimum_reserve} USDC minimum reserve.";

            return new PolicyEvaluationResult(
                decision: AgentDecisionType::HOLD,
                requestedAmount: $amount,
                approvedAmount: 0.00,
                requiresHumanApproval: true,
                policyCode: 'TREASURY_RESERVE_SAFETY_V1',
                reasoning: "Payment held to protect minimum operating reserve of {$org->minimum_reserve} USDC. Treasury would drop to {$postBalance} USDC.",
                violations: $violations,
                checks: $checks,
            );
        }

        if (! $checks['within_auto_limit']) {
            $violations[] = "Amount ({$amount} USDC) exceeds autonomous threshold ({$org->max_auto_payment} USDC).";

            return new PolicyEvaluationResult(
                decision: AgentDecisionType::ESCALATE,
                requestedAmount: $amount,
                approvedAmount: 0.00,
                requiresHumanApproval: true,
                policyCode: 'HIGH_VALUE_DISBURSEMENT_V1',
                reasoning: "Invoice amount ({$amount} USDC) exceeds the autonomous threshold of {$org->max_auto_payment} USDC. Escalated for human authorization.",
                violations: $violations,
                checks: $checks,
            );
        }

        if (! $checks['within_daily_cap']) {
            $violations[] = "Payment exceeds 24-hour autonomous disbursement cap ({$org->max_daily_disbursement} USDC).";

            return new PolicyEvaluationResult(
                decision: AgentDecisionType::ESCALATE,
                requestedAmount: $amount,
                approvedAmount: 0.00,
                requiresHumanApproval: true,
                policyCode: 'DAILY_VELOCITY_CAP_V1',
                reasoning: "Daily velocity cap of {$org->max_daily_disbursement} USDC reached for today. Escalated for approval.",
                violations: $violations,
                checks: $checks,
            );
        }

        return new PolicyEvaluationResult(
            decision: AgentDecisionType::AUTO_APPROVE,
            requestedAmount: $amount,
            approvedAmount: $amount,
            requiresHumanApproval: false,
            policyCode: 'VENDOR_AUTO_PAYMENT_V1',
            reasoning: "Approved for autonomous execution: verified vendor, funded budget, below {$org->max_auto_payment} USDC limit, and treasury remains safe above {$org->minimum_reserve} USDC reserve.",
            violations: [],
            checks: $checks,
        );
    }

    /**
     * Evaluate a student assistance / scholarship request.
     */
    public function evaluateStudentAssistance(
        float $requestedAmount,
        Organization $org,
        Wallet $wallet,
        ?Budget $aidBudget = null,
        float $autoAssistanceLimit = 100.00
    ): PolicyEvaluationResult {
        $checks = [
            'budget_available' => $aidBudget === null || $aidBudget->canAfford($requestedAmount),
            'reserve_protected' => ($wallet->balance - $requestedAmount) >= $org->minimum_reserve,
            'within_auto_aid_limit' => $requestedAmount <= $autoAssistanceLimit,
        ];

        $violations = [];

        if (! $checks['reserve_protected']) {
            $violations[] = 'Treasury reserve protection triggered.';

            return new PolicyEvaluationResult(
                decision: AgentDecisionType::HOLD,
                requestedAmount: $requestedAmount,
                approvedAmount: 0.00,
                requiresHumanApproval: true,
                policyCode: 'AID_RESERVE_SAFETY_V1',
                reasoning: 'Aid request temporarily held due to minimum treasury reserve requirements.',
                violations: $violations,
                checks: $checks,
            );
        }

        if (! $checks['budget_available']) {
            $violations[] = 'Assistance fund exhausted.';

            return new PolicyEvaluationResult(
                decision: AgentDecisionType::REJECT,
                requestedAmount: $requestedAmount,
                approvedAmount: 0.00,
                requiresHumanApproval: false,
                policyCode: 'AID_BUDGET_LIMIT_V1',
                reasoning: 'The student assistance budget does not currently have available allocation for this amount.',
                violations: $violations,
                checks: $checks,
            );
        }

        // Bounded split: if request exceeds auto-limit (e.g. 150 requested, 100 auto-limit)
        if ($requestedAmount > $autoAssistanceLimit) {
            return new PolicyEvaluationResult(
                decision: AgentDecisionType::PARTIAL_APPROVAL,
                requestedAmount: $requestedAmount,
                approvedAmount: $autoAssistanceLimit,
                requiresHumanApproval: true,
                policyCode: 'BOUNDED_EMERGENCY_AID_V1',
                reasoning: "{$autoAssistanceLimit} USDC approved automatically under Emergency Aid limits. Remaining ".($requestedAmount - $autoAssistanceLimit).' USDC escalated for advisor review.',
                violations: ["Request exceeds automatic assistance limit of {$autoAssistanceLimit} USDC."],
                checks: $checks,
            );
        }

        return new PolicyEvaluationResult(
            decision: AgentDecisionType::AUTO_APPROVE,
            requestedAmount: $requestedAmount,
            approvedAmount: $requestedAmount,
            requiresHumanApproval: false,
            policyCode: 'STUDENT_ASSISTANCE_AUTO_V1',
            reasoning: "Request of {$requestedAmount} USDC is within automatic emergency assistance limit ({$autoAssistanceLimit} USDC) and fully funded.",
            violations: [],
            checks: $checks,
        );
    }

    /**
     * Check if cumulative daily disbursements stay within the organization's daily cap.
     */
    protected function isWithinDailyCap(Organization $org, float $newAmount): bool
    {
        $todayDisbursed = (float) Transaction::where('organization_id', $org->id)
            ->where('created_at', '>=', Carbon::today())
            ->where('status', '!=', 'failed')
            ->sum('amount');

        return ($todayDisbursed + $newAmount) <= $org->max_daily_disbursement;
    }
}
