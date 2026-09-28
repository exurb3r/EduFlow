<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AgentDecisionType;

readonly class PolicyEvaluationResult
{
    /**
     * @param  list<string>  $violations
     * @param  array<string, bool>  $checks
     */
    public function __construct(
        public AgentDecisionType $decision,
        public float $requestedAmount,
        public float $approvedAmount,
        public bool $requiresHumanApproval,
        public string $policyCode,
        public string $reasoning,
        public array $violations = [],
        public array $checks = [],
    ) {}

    public function isAutoApproved(): bool
    {
        return $this->decision === AgentDecisionType::AUTO_APPROVE;
    }

    public function isEscalated(): bool
    {
        return $this->decision === AgentDecisionType::ESCALATE;
    }

    public function isHeld(): bool
    {
        return $this->decision === AgentDecisionType::HOLD;
    }

    public function isRejected(): bool
    {
        return $this->decision === AgentDecisionType::REJECT;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision->value,
            'requested_amount' => $this->requestedAmount,
            'approved_amount' => $this->approvedAmount,
            'requires_human_approval' => $this->requiresHumanApproval,
            'policy_code' => $this->policyCode,
            'reasoning' => $this->reasoning,
            'violations' => $this->violations,
            'checks' => $this->checks,
        ];
    }
}
