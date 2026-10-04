<?php

declare(strict_types=1);

namespace App\Actions;

use App\Ai\Advisory\AdvisoryEnvelope;
use App\Ai\Advisory\AdvisoryGate;
use App\DTOs\PolicyEvaluationResult;
use App\Enums\AgentDecisionType;
use App\Enums\CurrencyCode;
use App\Models\AgentDecision;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Services\CurrencyConverter;
use Illuminate\Support\Carbon;

class EvaluateAssistancePolicy
{
    public function __construct(
        private readonly CurrencyConverter $converter,
        private readonly AdvisoryGate $advisoryGate,
    ) {}

    /**
     * @return array{result: PolicyEvaluationResult, decision: AgentDecision, quote: array<string,mixed>}
     */
    public function handle(
        AssistanceRequest $request,
        AssistanceFund $fund,
        AssistancePolicyVersion $policy,
        CurrencyCode $displayCurrency = CurrencyCode::PHP,
    ): array {
        $student = $request->student;
        $requestedBase = (int) ($request->requested_amount ?? 0);
        $autoLimitBase = (int) $policy->auto_limit_base_units;

        $quote = $this->converter->lockQuote($displayCurrency);

        $attendance = $student ? (float) ($student->attendance_rate ?? 0) : 0;
        $enrollment = $student?->enrollment_status ?? 'unknown';
        $academic = $student?->academic_status ?? 'unknown';

        $outstandingBase = 0;
        if ($student) {
            foreach ($student->tuitionAccounts as $account) {
                $outstandingBase += max(0, $account->remainingAmount());
            }
        }

        $semesterSpentBase = 0;
        if ($student && $request->academic_term_id) {
            $semesterSpentBase = (int) AssistanceRequest::query()
                ->where('student_id', $student->id)
                ->where('academic_term_id', $request->academic_term_id)
                ->where('id', '!=', $request->id)
                ->whereIn('status', ['resolved', 'in_progress'])
                ->sum('requested_amount');
        }

        $todaySpentBase = (int) AgentDecision::query()
            ->where('organization_id', $fund->organization_id)
            ->where('action_type', 'student_assistance')
            ->where('created_at', '>=', Carbon::today())
            ->whereIn('status', ['executed', 'pending'])
            ->sum('approved_amount') * 1000000;

        $checks = [
            'enrolled' => $enrollment === $policy->required_enrollment_status,
            'academic_qualified' => $academic === $policy->required_academic_status,
            'attendance_ok' => $attendance >= (float) $policy->min_attendance_rate,
            'has_outstanding_tuition' => $outstandingBase > 0,
            'within_semester_cap' => ($semesterSpentBase + $requestedBase) <= (int) $policy->semester_cap_base_units,
            'fund_affordable' => $fund->canAfford($requestedBase),
            'reserve_protected' => $fund->isReserveProtected($requestedBase),
            'within_daily_budget' => ($todaySpentBase + $requestedBase) <= (int) $fund->daily_budget_base_units,
            'within_auto_limit' => $requestedBase <= $autoLimitBase,
        ];

        $toFloat = fn (int $base): float => round($base / 1000000, 2);
        $requestedFloat = $toFloat($requestedBase);
        $autoFloat = $toFloat($autoLimitBase);

        $fail = function (AgentDecisionType $decision, string $code, string $reason, array $violations, float $approved = 0.00, bool $needsApproval = false) use ($requestedFloat, $checks, $request, $fund, $policy, $quote): array {
            $result = new PolicyEvaluationResult(
                decision: $decision,
                requestedAmount: $requestedFloat,
                approvedAmount: $approved,
                requiresHumanApproval: $needsApproval,
                policyCode: $code,
                reasoning: $reason,
                violations: $violations,
                checks: $checks,
            );

            $decisionModel = $this->recordDecision($request, $fund, $policy, $result, $quote);

            return ['result' => $result, 'decision' => $decisionModel, 'quote' => $quote];
        };

        if (! $checks['enrolled']) {
            return $fail(AgentDecisionType::REJECT, 'AID_ENROLLMENT_V1', "Student enrollment status [{$enrollment}] does not meet [{$policy->required_enrollment_status}].", ['Enrollment check failed.']);
        }

        if (! $checks['academic_qualified']) {
            return $fail(AgentDecisionType::REJECT, 'AID_ACADEMIC_V1', "Academic status [{$academic}] does not meet [{$policy->required_academic_status}].", ['Academic standing check failed.']);
        }

        if (! $checks['attendance_ok']) {
            return $fail(AgentDecisionType::REJECT, 'AID_ATTENDANCE_V1', "Attendance {$attendance}% is below {$policy->min_attendance_rate}%.", ['Attendance threshold not met.']);
        }

        if (! $checks['has_outstanding_tuition']) {
            return $fail(AgentDecisionType::REJECT, 'AID_TUITION_V1', 'No outstanding tuition balance found.', ['Outstanding tuition required.']);
        }

        if (! $checks['within_semester_cap']) {
            return $fail(AgentDecisionType::ESCALATE, 'AID_SEMESTER_CAP_V1', 'Semester assistance cap would be exceeded.', ['Semester cap exceeded.'], 0.00, true);
        }

        if (! $checks['fund_affordable']) {
            return $fail(AgentDecisionType::REJECT, 'AID_BUDGET_LIMIT_V1', 'Assistance fund does not have available allocation.', ['Assistance fund exhausted.']);
        }

        if (! $checks['reserve_protected']) {
            return $fail(AgentDecisionType::HOLD, 'AID_RESERVE_SAFETY_V1', 'Request held to protect minimum reserve.', ['Reserve protection triggered.'], 0.00, true);
        }

        if (! $checks['within_daily_budget']) {
            return $fail(AgentDecisionType::ESCALATE, 'DAILY_VELOCITY_CAP_V1', 'Daily assistance budget reached.', ['Daily budget exceeded.'], 0.00, true);
        }

        if (! $checks['within_auto_limit']) {
            $remainingBase = $requestedBase - $autoLimitBase;
            $result = new PolicyEvaluationResult(
                decision: AgentDecisionType::PARTIAL_APPROVAL,
                requestedAmount: $requestedFloat,
                approvedAmount: $autoFloat,
                requiresHumanApproval: true,
                policyCode: 'BOUNDED_EMERGENCY_AID_V1',
                reasoning: "{$autoFloat} USDC approved automatically. Remaining ".round($remainingBase / 1000000, 2).' USDC escalated for review.',
                violations: ["Exceeds automatic limit of {$autoFloat} USDC."],
                checks: $checks,
            );

            $decisionModel = $this->recordDecision($request, $fund, $policy, $result, $quote);

            return ['result' => $result, 'decision' => $decisionModel, 'quote' => $quote];
        }

        $result = new PolicyEvaluationResult(
            decision: AgentDecisionType::AUTO_APPROVE,
            requestedAmount: $requestedFloat,
            approvedAmount: $requestedFloat,
            requiresHumanApproval: false,
            policyCode: 'STUDENT_ASSISTANCE_AUTO_V1',
            reasoning: "Request of {$requestedFloat} USDC is within automatic limit ({$autoFloat} USDC) and fully funded.",
            violations: [],
            checks: $checks,
        );

        $decisionModel = $this->recordDecision($request, $fund, $policy, $result, $quote);

        return ['result' => $result, 'decision' => $decisionModel, 'quote' => $quote];
    }

    /**
     * Record the deterministic decision first, then attach advisory commentary.
     *
     * Order matters and is the whole point of the three-tier model: the row
     * exists, complete and authoritative, before any model is consulted. The
     * advisory is written afterwards under a namespaced `advisory` key, so a
     * provider outage, a timeout or a hostile response cannot delay, alter or
     * prevent the decision.
     */
    private function recordDecision(
        AssistanceRequest $request,
        AssistanceFund $fund,
        AssistancePolicyVersion $policy,
        PolicyEvaluationResult $result,
        array $quote,
    ): AgentDecision {
        $decision = AgentDecision::create([
            'organization_id' => $fund->organization_id,
            'action_type' => 'student_assistance',
            'reference_type' => AssistanceRequest::class,
            'reference_id' => $request->id,
            'input_snapshot' => [
                'ticket' => $request->ticket_number,
                'requested_base_units' => (int) ($request->requested_amount ?? 0),
                'fund_id' => $fund->id,
                'policy_version' => $policy->version,
                'locked_quote' => $quote,
                'checks' => $result->checks,
            ],
            'reasoning_summary' => $result->reasoning,
            'policy_checked' => $result->policyCode,
            'decision' => $result->decision,
            'requested_amount' => $result->requestedAmount,
            'approved_amount' => $result->approvedAmount,
            'requires_approval' => $result->requiresHumanApproval,
            'status' => $result->decision === AgentDecisionType::AUTO_APPROVE ? 'pending' : 'escalated',
        ]);

        return $this->attachAdvisory($decision, $request);
    }

    /**
     * Best-effort model commentary. Never throws, and never runs in the
     * synchronous settlement path unless explicitly enabled: AdvisoryGate
     * returns null whenever the admin switches are off or a provider fails.
     */
    private function attachAdvisory(AgentDecision $decision, AssistanceRequest $request): AgentDecision
    {
        try {
            $advisory = $this->advisoryGate->assessHardship([
                'reason' => (string) ($request->reason ?? ''),
                'category' => $request->category instanceof \BackedEnum ? $request->category->value : (string) $request->category,
                'attendance_rate' => (float) ($request->student?->attendance_rate ?? 0),
                'enrollment_status' => (string) ($request->student?->enrollment_status ?? ''),
                'academic_status' => (string) ($request->student?->academic_status ?? ''),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return $decision;
        }

        if (! $advisory instanceof AdvisoryEnvelope) {
            return $decision;
        }

        $decision->update([
            'metadata' => $advisory->mergeIntoMetadata($decision->metadata ?? []),
        ]);

        return $decision->refresh();
    }
}
