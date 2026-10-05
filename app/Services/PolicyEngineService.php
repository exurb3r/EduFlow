<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AgentDecision;
use App\Models\Organization;
use App\Models\StudentAssistanceRequest;
use App\Models\Wallet;

/**
 * Deterministic policy enforcement for student monetary assistance.
 *
 * The agent (LLM) may only RECOMMEND a decision; this engine decides what
 * the application actually permits. Modeled on plan.md section 21:
 * the AI reasons, the policy engine authorizes.
 */
final class PolicyEngineService
{
    /**
     * Automatic assistance limit, in organization currency units.
     * Requests at or below this are paid instantly; anything above is
     * partially approved up to the limit and escalated for human review.
     */
    public const MAX_AUTO_ASSISTANCE = 100.0;

    public const POLICY_NAME = 'EMERGENCY_ASSISTANCE_V1';

    /**
     * @return array{decision: string, approved_amount: float, escalated_amount: float, requires_human_approval: bool, checks: list<array{label: string, passed: bool}>, reason: string}
     */
    public function evaluate(StudentAssistanceRequest $request, Organization $organization): array
    {
        $requested = (float) $request->requested_amount;
        $limit = min(self::MAX_AUTO_ASSISTANCE, $organization->max_auto_payment);

        $assistanceBudget = $organization->budgets()
            ->where('category', 'scholarships')
            ->first();

        $budgetRemaining = $assistanceBudget !== null
            ? (float) $assistanceBudget->remaining_amount
            : 0.0;

        $wallet = $organization->primaryWallet();
        $treasury = $wallet instanceof Wallet ? (float) $wallet->balance : 0.0;

        $checks = [
            [
                'label' => "Within automatic limit ({$limit} ".($organization->currency).')',
                'passed' => $requested <= $limit,
            ],
            [
                'label' => 'Assistance budget available',
                'passed' => $budgetRemaining >= min($requested, $limit),
            ],
            [
                'label' => 'Treasury remains above minimum reserve',
                'passed' => ($treasury - min($requested, $limit)) >= (float) $organization->minimum_reserve,
            ],
        ];

        $allPassed = ! in_array(false, array_column($checks, 'passed'), true);

        /* Funding checks (budget + reserve) are the ones that gate the
           auto portion; the limit check is expected to fail for over-limit
           requests — it is the REASON the request splits, not a blocker. */
        $fundingPassed = $checks[1]['passed'] && $checks[2]['passed'];

        if ($requested <= $limit && $allPassed) {
            return [
                'decision' => 'auto_approve',
                'approved_amount' => $requested,
                'escalated_amount' => 0.0,
                'requires_human_approval' => false,
                'checks' => $checks,
                'reason' => "Request of {$requested} ".($organization->currency).' is within the automatic assistance limit, the assistance budget is funded, and the projected treasury remains above the minimum reserve.',
            ];
        }

        if ($requested > $limit && $fundingPassed) {
            return [
                'decision' => 'partial_approval',
                'approved_amount' => $limit,
                'escalated_amount' => round($requested - $limit, 2),
                'requires_human_approval' => true,
                'checks' => $checks,
                'reason' => "First {$limit} ".($organization->currency).' approved automatically under '.self::POLICY_NAME.'; the remaining '.round($requested - $limit, 2).' '.($organization->currency).' exceeds the autonomous limit and was escalated for human approval.',
            ];
        }

        return [
            'decision' => 'escalate',
            'approved_amount' => 0.0,
            'escalated_amount' => $requested,
            'requires_human_approval' => true,
            'checks' => $checks,
            'reason' => 'Requested amount could not be released automatically: a funding or reserve check did not pass. A finance officer will review this request.',
        ];
    }

    public function recordDecision(StudentAssistanceRequest $request, Organization $organization, array $evaluation): AgentDecision
    {
        return AgentDecision::create([
            'organization_id' => $organization->id,
            'action_type' => 'student_assistance',
            'reference_type' => 'student_assistance_request',
            'reference_id' => $request->id,
            'input_snapshot' => [
                'requested_amount' => (float) $request->requested_amount,
                'request_type' => $request->request_type,
                'reason' => $request->reason,
                'checks' => $evaluation['checks'],
                'escalated_amount' => $evaluation['escalated_amount'],
            ],
            'reasoning_summary' => $evaluation['reason'],
            'policy_checked' => self::POLICY_NAME,
            'decision' => $evaluation['decision'],
            'requested_amount' => $request->requested_amount,
            'approved_amount' => $evaluation['approved_amount'],
            'requires_approval' => $evaluation['requires_human_approval'],
            'status' => match ($evaluation['decision']) {
                'auto_approve', 'partial_approval' => 'executed',
                'escalate' => 'escalated',
                default => 'pending',
            },
        ]);
    }
}
