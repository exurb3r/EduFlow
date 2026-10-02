<?php

declare(strict_types=1);

namespace App\Ai\Approvals;

/**
 * What happened when a reviewer answered a paused tool call.
 *
 * readonly for the same reason `AdvisoryEnvelope` and `QnaAnswer` are: a
 * mutable result object travelling out of a security gate is where a field
 * would get repurposed into an amount or a verdict.
 */
final readonly class ApprovalResumeOutcome
{
    /**
     * @param  list<string>  $approved  tool call ids a human approved
     * @param  list<string>  $rejected  tool call ids a human refused
     * @param  list<array{tool_call_id: string, summary: string}>  $settlements  disbursements the resumed run executed, each a claim still requiring on-chain proof
     */
    private function __construct(
        public string $status,
        public string $conversationId,
        public array $approved,
        public array $rejected,
        public array $settlements,
        public ?string $agentSummary = null,
    ) {}

    /**
     * The reviewer answered, and the run continued.
     *
     * @param  list<string>  $approved
     * @param  list<string>  $rejected
     * @param  list<array{tool_call_id: string, summary: string}>  $settlements
     */
    public static function resumed(
        string $conversationId,
        array $approved,
        array $rejected,
        array $settlements,
        ?string $agentSummary = null,
    ): self {
        return new self('resumed', $conversationId, $approved, $rejected, $settlements, $agentSummary);
    }

    /**
     * Nothing was decided, because nothing was waiting.
     *
     * This is the replay case: a run that was already answered has no paused
     * turn left, so a repeated approval cannot pay anyone twice.
     */
    public static function nothingPending(string $conversationId): self
    {
        return new self('nothing_pending', $conversationId, [], [], []);
    }

    /**
     * The run paused again, so the decision did not settle the whole turn.
     */
    public static function pausedAgain(
        string $conversationId,
        array $approved,
        array $rejected,
        array $stillPending,
    ): self {
        return new self(
            'still_pending',
            $conversationId,
            $approved,
            $rejected,
            [],
            $stillPending === [] ? null : 'Waiting on: '.implode(', ', $stillPending),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'conversation_id' => $this->conversationId,
            'approved' => $this->approved,
            'rejected' => $this->rejected,
            'settlements' => $this->settlements,
            'summary' => $this->agentSummary,
            // A returned tx hash is a claim. Nothing here has been proven
            // on-chain; lepton:reconcile does that.
            'requires_onchain_verification' => $this->settlements !== [],
        ];
    }
}
