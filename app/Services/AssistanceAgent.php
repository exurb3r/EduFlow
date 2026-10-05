<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use App\Models\StudentAssistanceRequest;

/**
 * Placeholder for the LLM-backed agent. Until the model integration
 * lands, this produces the same STRUCTURED decision shape the real
 * agent will emit (plan.md section 7) so the UI, policy engine, and
 * payment pipeline are exercised end to end.
 */
final readonly class AssistanceAgent
{
    public function __construct(
        private PolicyEngineService $policyEngine,
    ) {}

    /**
     * @return array{decision: string, approved_amount: float, escalated_amount: float, requires_human_approval: bool, checks: list<array{label: string, passed: bool}>, reason: string, policy: string, agent: string}
     */
    public function review(StudentAssistanceRequest $request, Organization $organization): array
    {
        $evaluation = $this->policyEngine->evaluate($request, $organization);

        $evaluation['policy'] = PolicyEngineService::POLICY_NAME;
        $evaluation['agent'] = 'EduFlow AssistanceAgent (heuristic v0 — LLM pending)';

        return $evaluation;
    }

    public function record(StudentAssistanceRequest $request, Organization $organization, array $evaluation): void
    {
        $this->policyEngine->recordDecision($request, $organization, $evaluation);
    }
}
