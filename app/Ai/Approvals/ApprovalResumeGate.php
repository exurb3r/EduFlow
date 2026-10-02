<?php

declare(strict_types=1);

namespace App\Ai\Approvals;

use App\Ai\Agents\SettlementOperatorFactory;
use App\Models\AgentDecision;
use App\Models\Approval;
use App\Models\AssistanceRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Settings\AiSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\ResolvesPendingApprovals;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Models\Conversation;
use Throwable;

/**
 * The only path from a human decision to a resumed tool call.
 *
 * Six checks stand between a reviewer clicking approve and money moving, and
 * each exists because the obvious implementation is wrong in a specific way:
 *
 *  1. **Authorisation.** Only a role that may approve assistance may resume.
 *     Verified through the Gate, not by reading a role name here, so the panel
 *     and this endpoint cannot drift apart.
 *  2. **Conversation ownership.** `RemembersConversations::continue()` trusts any
 *     conversation id it is given. Without `conversationBelongsTo()`, any
 *     authenticated reviewer could resume another officer's paused run, and
 *     approving it would release that officer's disbursement.
 *  3. **Pending set.** Submitted ids must be a subset of what is genuinely
 *     paused in that conversation, so a stale or foreign id is refused instead
 *     of passed through to the SDK.
 *  4. **Tool allowlist.** Only `DisburseAssistance` may be approved, and its
 *     arguments are not editable. Authorising the proposal the model actually
 *     made is a different act from rewriting it.
 *  5. **Provenance of the target.** The assistance request id is read from the
 *     *stored* pending approval, never from the submitted payload, so a caller
 *     cannot point an approval at a different student.
 *  6. **The settings gate.** With settlement proposals off there is nothing
 *     legitimate to approve, so the resume is refused.
 *
 * A hash returned here is a claim, never proof. `settlements` reports what the
 * ledger recorded, and `requires_onchain_verification` stays true until
 * `lepton:reconcile` proves it on-chain.
 */
final readonly class ApprovalResumeGate
{
    /**
     * Tools a human may authorise. Anything else is refused.
     *
     * @var list<string>
     */
    private const APPROVABLE_TOOLS = ['DisburseAssistance'];

    public function __construct(
        private AiSettings $settings,
        private AiProviderResolver $providers,
        private ConversationStore $conversations,
        private VerifiesConversationOwnership $ownership,
        private ResolvesPendingApprovals $pending,
    ) {}

    /**
     * Answer the paused tool calls in a conversation.
     *
     * @param  array<string, bool>  $decisions  tool call id => approve?
     *
     * @throws AuthorizationException when the reviewer may not approve, does not own the
     *                                conversation, or asks for something that is not paused
     */
    public function resume(User $reviewer, string $conversationId, array $decisions): ApprovalResumeOutcome
    {
        $this->authorize($reviewer, $conversationId);

        // A switch turned off after the run paused is a deliberate operator
        // decision to stop model-proposed settlements, and a resume would move
        // funds on exactly that path. Refuse rather than honour the pause.
        //
        // Deliberately *not* in authorize(), so listing pending stays available:
        // an officer who cannot see why a decision is refused has no way to
        // diagnose it.
        if (! $this->settings->mayProposeSettlements()) {
            throw new AuthorizationException('Settlement proposals are switched off, so this run cannot be resumed.');
        }

        $pending = $this->pending->pendingApprovalsFor($conversationId);

        if ($pending === []) {
            // Already answered, or never paused. Returning early is what makes a
            // replayed approval inert rather than a second payout.
            return ApprovalResumeOutcome::nothingPending($conversationId);
        }

        [$resolved, $targets, $approvedTargets] = $this->decide($pending, $decisions);

        $approved = array_keys(array_filter($resolved, fn (Decision $d): bool => $d->isApproved()));
        $rejected = array_keys(array_filter($resolved, fn (Decision $d): bool => $d->isRejected()));

        // Attribute the human before resuming, so an interrupted run still leaves
        // an audit trail of who authorised it. Only *approved* proposals are
        // attributed: a rejection is a refusal to release funds, and recording it
        // as an approval would assert a transfer no human agreed to.
        $this->recordAttribution($reviewer, $approvedTargets);

        $result = $this->run($reviewer, $conversationId, $resolved, $targets);

        return $result['still_pending'] !== []
            ? ApprovalResumeOutcome::pausedAgain($conversationId, $approved, $rejected, $result['still_pending'])
            : ApprovalResumeOutcome::resumed($conversationId, $approved, $rejected, $result['settlements'], $result['summary']);
    }

    /**
     * The tool calls a conversation's newest turn is waiting on.
     *
     * @return list<PendingApproval>
     *
     * @throws AuthorizationException
     */
    public function pendingFor(User $reviewer, string $conversationId): array
    {
        $this->authorize($reviewer, $conversationId);

        return $this->pending->pendingApprovalsFor($conversationId);
    }

    /**
     * Reject the request unless the reviewer may approve and owns the thread.
     *
     * @throws AuthorizationException
     */
    private function authorize(User $reviewer, string $conversationId): void
    {
        if ($reviewer->cannot('approveSettlementProposals')) {
            throw new AuthorizationException('Only a finance officer may answer a disbursement proposal.');
        }

        // Ownership, not role. Two officers both being allowed to approve does
        // not mean either may answer the other's paused run.
        if (! $this->ownership->conversationBelongsTo(
            $conversationId,
            Conversation::participantType($reviewer),
            Conversation::participantKey($reviewer),
        )) {
            throw new AuthorizationException('That conversation belongs to another reviewer.');
        }
    }

    /**
     * Turn submitted decisions into validated Decisions plus the request ids they target.
     *
     * @param  list<PendingApproval>  $pending
     * @param  array<string, bool>  $decisions
     * @return array{0: array<string, Decision>, 1: list<int>, 2: list<int>} decisions, all targets, approved targets
     *
     * @throws AuthorizationException
     */
    private function decide(array $pending, array $decisions): array
    {
        /** @var array<string, PendingApproval> $known */
        $known = [];

        foreach ($pending as $approval) {
            $known[$approval->id] = $approval;
        }

        $resolved = [];
        $targets = [];
        $approvedTargets = [];

        foreach ($decisions as $toolCallId => $approve) {
            $toolCallId = (string) $toolCallId;

            if (! isset($known[$toolCallId])) {
                // Not paused in this conversation. Passing it through would let a
                // caller address an approval belonging to an unrelated run.
                throw new AuthorizationException("Tool call {$toolCallId} is not awaiting a decision here.");
            }

            if (! in_array($known[$toolCallId]->tool, self::APPROVABLE_TOOLS, true)) {
                throw new AuthorizationException("The {$known[$toolCallId]->tool} tool cannot be approved here.");
            }

            $resolved[$toolCallId] = $approve
                ? Decision::approve()
                : Decision::reject('A human reviewer declined this proposal.');

            $requestId = (int) ($known[$toolCallId]->arguments['assistance_request_id'] ?? 0);

            if ($requestId > 0) {
                $targets[] = $requestId;

                if ($approve) {
                    $approvedTargets[] = $requestId;
                }
            }
        }

        if ($resolved === []) {
            throw new AuthorizationException('No decision was submitted.');
        }

        return [
            $resolved,
            array_values(array_unique($targets)),
            array_values(array_unique($approvedTargets)),
        ];
    }

    /**
     * Resume the run and report what the ledger recorded.
     *
     * @param  array<string, Decision>  $resolved
     * @param  list<int>  $targets
     * @return array{settlements: list<array{tool_call_id: string, summary: string}>, still_pending: list<string>, summary: ?string}
     */
    private function run(User $reviewer, string $conversationId, array $resolved, array $targets): array
    {
        $provider = $this->providers->resolve();

        if ($provider === null) {
            throw new AuthorizationException('No AI provider is configured, so the run cannot be resumed.');
        }

        $before = $targets === []
            ? []
            : Transaction::query()
                ->where('reference_type', AssistanceRequest::class)
                ->whereIn('reference_id', $targets)
                ->pluck('id')
                ->all();

        // Through the factory, not the container. Container-resolving the agent
        // yields a *blank* Organization, whose primaryWallet() is null, so the
        // tool would refuse for the wrong reason. See SettlementOperatorFactory.
        $operator = SettlementOperatorFactory::make();

        if ($operator === null) {
            throw new AuthorizationException(
                'The settlement operator is not configured: no organization, assistance fund or active policy version.'
            );
        }

        try {
            $response = $operator
                ->continue($conversationId, as: $reviewer)
                ->prompt(
                    Decisions::from($resolved),
                    provider: $provider,
                    timeout: $this->settings->timeout_seconds,
                );
        } catch (ApprovalMismatchException $e) {
            // The paused turn no longer matches what was submitted. Reported as a
            // refusal rather than an error, so a replay cannot pay anyone.
            return [
                'settlements' => [],
                'still_pending' => [],
                'summary' => 'Already answered: '.$e->getMessage(),
            ];
        } catch (Throwable $e) {
            report($e);

            return [
                'settlements' => [],
                'still_pending' => [],
                'summary' => 'The run could not be resumed: '.$e->getMessage(),
            ];
        }

        $settlements = $targets === []
            ? []
            : Transaction::query()
                ->where('reference_type', AssistanceRequest::class)
                ->whereIn('reference_id', $targets)
                ->whereNotIn('id', $before)
                ->get()
                ->map(fn (Transaction $tx): array => [
                    'tool_call_id' => $this->toolCallFor($tx, $targets),
                    'summary' => sprintf(
                        'Recorded %s USDC to %s as tx %s. This is a claim, not proof: run lepton:reconcile to verify it on-chain.',
                        number_format((float) $tx->amount, 2),
                        $tx->recipient_address,
                        $tx->provider_tx_hash ?? 'pending',
                    ),
                ])
                ->values()
                ->all();

        return [
            'settlements' => $settlements,
            'still_pending' => array_map(
                fn (PendingApproval $approval): string => $approval->id,
                $this->pending->pendingApprovalsFor($conversationId),
            ),
            'summary' => $this->summarise($response),
        ];
    }

    /**
     * Attribute each approved proposal to the human who authorised it.
     *
     * @param  list<int>  $targets
     */
    private function recordAttribution(User $reviewer, array $targets): void
    {
        if ($targets === []) {
            return;
        }

        $decisions = AgentDecision::query()
            ->where('reference_type', AssistanceRequest::class)
            ->whereIn('reference_id', $targets)
            ->where('requires_approval', true)
            ->latest('id')
            ->get();

        foreach ($decisions as $decision) {
            Approval::updateOrCreate(
                ['agent_decision_id' => $decision->id],
                [
                    'organization_id' => $decision->organization_id,
                    'approver_id' => $reviewer->id,
                    'status' => 'approved',
                    'comment' => 'Authorised by a human reviewer through the agent approval endpoint.',
                    'approved_at' => now(),
                ],
            );
        }
    }

    /**
     * @param  list<int>  $targets
     */
    private function toolCallFor(Transaction $tx, array $targets): string
    {
        return in_array((int) $tx->reference_id, $targets, true)
            ? (string) $tx->reference_id
            : 'unknown';
    }

    private function summarise(mixed $response): ?string
    {
        $text = trim((string) $response);

        return $text === '' ? null : $text;
    }
}
