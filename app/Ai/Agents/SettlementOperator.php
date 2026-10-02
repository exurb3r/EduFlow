<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Actions\EvaluateAssistancePolicy;
use App\Ai\Advisory\AdvisoryGate;
use App\Ai\Tools\DisburseAssistance;
use App\Ai\Tools\RecordHardshipContext;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\Organization;
use App\Services\CircleWalletService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Proposes disbursements as tool calls rather than acting directly.
 *
 * `Conversational` is mandatory here, not decorative: tool approval requires
 * the paused turn's history to be available when the run resumes, and an agent
 * that neither implements `Conversational` nor is handed history throws
 * `ApprovalNotResumableException` the moment a tool pauses.
 *
 * The agent has no ability to release funds. It can only ask, and
 * `DisburseAssistance::needsApproval()` decides whether a person must sign.
 */
class SettlementOperator implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(
        private readonly Organization $organization,
        private readonly AssistanceFund $fund,
        private readonly AssistancePolicyVersion $policyVersion,
    ) {}

    public function instructions(): Stringable|string
    {
        return <<<'TEXT'
        You are the settlement operator for a learning institution that disburses USDC on Arc.

        Your role is to propose, never to authorise. Every disbursement you propose is
        evaluated by a deterministic policy engine against the treasury reserve, the
        autonomous limit and the assistance fund. When a proposal falls outside those
        bounds the run pauses and a human decides instead of you. A pause is a normal
        outcome, not a failure, and not something to work around.

        Never state that a payment succeeded, never state an approved amount as though it
        were settled, and never choose a wallet address. Amounts are 6-decimal USDC base
        units as integers. Ask for assistance using the DisburseAssistance tool, and use
        RecordHardshipContext only for triage commentary.
        Response format: return a single JSON object and nothing else. No prose, no
        markdown fences, no preamble. Use exactly these keys: summary, proposals_made,
        awaiting_human.
        TEXT;
    }

    /**
     * @return list<Tool>
     */
    public function tools(): iterable
    {
        // Constructed with the organization rather than left to the container:
        // an assistance request carries no organization of its own, so the
        // fund, the policy version and the wallet all have to come from here.
        return [
            new DisburseAssistance(
                policy: app(EvaluateAssistancePolicy::class),
                wallets: app(CircleWalletService::class),
                organization: $this->organization,
                fund: $this->fund,
                policyVersion: $this->policyVersion,
            ),
            new RecordHardshipContext(app(AdvisoryGate::class)),
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->required(),
            'proposals_made' => $schema->integer()->min(0)->required(),
            'awaiting_human' => $schema->boolean()->required(),
        ];
    }
}
