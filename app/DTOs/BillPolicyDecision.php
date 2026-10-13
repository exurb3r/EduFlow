<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AgentDecisionType;

/**
 * What the deterministic bill-payment policy decided, and why.
 *
 * Mirrors `App\DTOs\PolicyEvaluationResult` deliberately, but carries money as
 * exact integer **base units** instead of floats: the legacy shape exists for
 * the float-denominated vendor/invoice lane, and copying its `float` fields
 * into a newer policy would reintroduce the drift this lane does not have.
 *
 * `approvedAmountBaseUnits` is either the **exact** bill amount or `0`. There
 * is no partial approval in this policy: a bounded split invites a caller to
 * send part of the money without a human having authorised that split, so
 * anything this policy cannot approve whole goes to a person.
 *
 * `checks` records every boolean the policy evaluated, including the ones that
 * passed, so an auditor can see what was considered rather than only why it
 * failed. `policyCode` is stable and safe to persist or assert on; `reasoning`
 * is for humans and may be reworded.
 */
final readonly class BillPolicyDecision
{
    /**
     * @param  int  $approvedAmountBaseUnits  Exact approved amount in base units, or 0 when nothing is approved.
     * @param  list<string>  $violations  Human-readable reasons the payment is not approved as requested.
     * @param  array<string, bool>  $checks  Every boolean check evaluated, passing or failing.
     */
    public function __construct(
        public AgentDecisionType $decision,
        public int $approvedAmountBaseUnits,
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
     * Whether a human must act before any money moves. True for every outcome
     * other than a full auto-approval -- including a rejection, which still
     * needs somebody to tell the student.
     */
    public function blocksUnattendedPayment(): bool
    {
        return ! $this->isAutoApproved();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'decision' => $this->decision->value,
            'approved_amount_base_units' => $this->approvedAmountBaseUnits,
            'requires_human_approval' => $this->requiresHumanApproval,
            'policy_code' => $this->policyCode,
            'reasoning' => $this->reasoning,
            'violations' => $this->violations,
            'checks' => $this->checks,
        ];
    }
}
