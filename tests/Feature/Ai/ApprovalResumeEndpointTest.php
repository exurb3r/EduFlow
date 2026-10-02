<?php

declare(strict_types=1);

use App\Ai\Agents\SettlementOperator;
use App\Ai\Agents\SettlementOperatorFactory;
use App\Ai\Approvals\ApprovalResumeGate;
use App\Enums\AgentDecisionType;
use App\Enums\AssistanceStatus;
use App\Models\AcademicTerm;
use App\Models\AgentDecision;
use App\Models\AiProvider;
use App\Models\Approval;
use App\Models\AssistanceFund;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\User;
use App\Settings\AiSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\AgentResponse;
use Spatie\Permission\Models\Role;

/**
 * The approval-resume endpoint.
 *
 * The claim under test is narrow and absolute: only the officer who owns a
 * paused conversation, holding a role permitted to release funds, can answer
 * it — and only for a tool that is genuinely waiting there. Everything else,
 * including a replay, must leave the ledger untouched.
 *
 * The resume itself is driven through the SDK's fake gateway, so these never
 * touch a chain or a provider.
 */

/** Turn on the provider and allow settlement proposals. */
function enableSettlementProposals(): void
{
    $settings = app(AiSettings::class);
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    $settings->allow_settlement_proposals = true;
    $settings->save();

    app()->forgetInstance(AiSettings::class);
}

function configureProvider(): void
{
    AiProvider::create([
        'name' => 'Test Gateway',
        'driver' => 'openai-compatible',
        'base_url' => 'https://gateway.test/v1',
        'model' => 'test-model',
        'api_key' => 'sk-test',
        'is_active' => true,
        'is_default' => true,
    ]);
}

function reviewer(string $email = 'officer@eduflow.test'): User
{
    $user = User::factory()->create(['email' => $email]);
    $user->assignRole(Role::findOrCreate('finance_officer', 'web'));

    return $user;
}

function escalatedRequest(int $baseUnits = 150_000000): AssistanceRequest
{
    return AssistanceRequest::factory()->create([
        'student_id' => Student::firstOrFail()->id,
        'academic_term_id' => AcademicTerm::firstOrFail()->id,
        'requested_amount' => $baseUnits,
        'status' => AssistanceStatus::SUBMITTED,
    ]);
}

/**
 * Pause a SettlementOperator run owned by the given reviewer.
 *
 * Returns the conversation id.
 */
function pausedConversation(User $owner, AssistanceRequest $request, string $toolCallId = 'call_abc'): string
{
    // A paused run implies needsApproval() already ran, and that re-evaluates
    // policy, which records the decision a human is being asked to authorise.
    // Seeding it keeps attribution honest without invoking the tool twice.
    AgentDecision::create([
        'organization_id' => Organization::firstOrFail()->id,
        'action_type' => 'student_assistance',
        'reference_type' => AssistanceRequest::class,
        'reference_id' => $request->id,
        'input_snapshot' => ['ticket' => $request->ticket_number],
        'reasoning_summary' => 'Above the autonomous limit, so a human must authorise the remainder.',
        'policy_checked' => 'BOUNDED_EMERGENCY_AID_V1',
        'decision' => AgentDecisionType::ESCALATE,
        'requested_amount' => (int) $request->requested_amount / 1000000,
        'approved_amount' => 0,
        'requires_approval' => true,
        'status' => 'escalated',
    ]);

    SettlementOperator::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval(
                id: $toolCallId,
                tool: 'DisburseAssistance',
                arguments: ['assistance_request_id' => $request->id, 'reason' => 'over limit'],
                reason: 'Amount exceeds the autonomous threshold.',
            ),
        ]),
    ]);

    $paused = SettlementOperator::make()
        ->forUser($owner)
        ->prompt('Disburse the open assistance request.');

    expect($paused->hasPendingApprovals())->toBeTrue();

    return (string) $paused->conversationId;
}

beforeEach(function (): void {
    config(['lepton.default' => 'fake']);

    $this->seed(DatabaseSeeder::class);

    enableSettlementProposals();
    configureProvider();

    $this->officer = reviewer();
    $this->fund = AssistanceFund::where('organization_id', Organization::firstOrFail()->id)->firstOrFail();
});

it('lists the tool calls a paused conversation is waiting on', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    $response = $this->actingAs($this->officer)->postJson(route('finance.approvals.pending'), [
        'conversation_id' => $conversation,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.pending.0.id', 'call_abc')
        ->assertJsonPath('data.pending.0.tool', 'DisburseAssistance')
        // The stored arguments, so the reviewer approves the real proposal.
        ->assertJsonPath('data.pending.0.arguments.assistance_request_id', $request->id);
});

it('resumes an owned conversation and records the reviewer', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    SettlementOperator::fake(['Resumed after review.']);

    $response = $this->actingAs($this->officer)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => $conversation,
        'decisions' => ['call_abc' => true],
    ]);

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'resumed')
        ->assertJsonPath('data.approved.0', 'call_abc');

    // The human is attributed on the decision that needed authority.
    $approval = Approval::where('approver_id', $this->officer->id)->first();

    expect($approval)->not->toBeNull()
        ->and($approval->status)->toBe('approved')
        ->and($approval->agentDecision->reference_id)->toBe($request->id);
});

it('reports a settlement as a claim requiring on-chain verification', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    SettlementOperator::fake(['Done.']);

    $response = $this->actingAs($this->officer)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => $conversation,
        'decisions' => ['call_abc' => true],
    ]);

    // Whether or not a transfer was recorded, the response must not claim proof.
    expect($response->json('data.requires_onchain_verification'))->toBeBool();

    foreach ($response->json('data.settlements') as $settlement) {
        expect($settlement['summary'])->toContain('claim, not proof')
            ->and($settlement['summary'])->toContain('lepton:reconcile');
    }
});

it('refuses a reviewer who does not hold an approving role', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    // A student owns a conversation but may not release funds.
    $studentUser = User::factory()->create();
    $studentUser->assignRole(Role::findOrCreate('student', 'web'));
    Student::factory()->create(['user_id' => $studentUser->id, 'student_number' => 'STU-2026-0999']);

    $theirs = pausedConversation($studentUser, $request, 'call_student');

    $this->actingAs($studentUser)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => $theirs,
        'decisions' => ['call_student' => true],
    ])->assertForbidden();

    expect(Transaction::count())->toBe(0);
});

it('refuses an officer resuming a conversation they do not own', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    // A second finance officer has the role but not the thread.
    $other = reviewer('officer2@eduflow.test');

    $this->actingAs($other)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => $conversation,
        'decisions' => ['call_abc' => true],
    ])->assertForbidden();

    // The real owner is unaffected, and nothing moved.
    $this->actingAs($this->officer)->postJson(route('finance.approvals.pending'), [
        'conversation_id' => $conversation,
    ])->assertOk()->assertJsonCount(1, 'data.pending');
});

it('refuses a listing for a conversation the caller does not own', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    $other = reviewer('officer2@eduflow.test');

    $this->actingAs($other)->postJson(route('finance.approvals.pending'), [
        'conversation_id' => $conversation,
    ])->assertForbidden();
});

it('refuses a decision for a tool call that is not paused here', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    SettlementOperator::fake(['Resumed.']);

    $this->actingAs($this->officer)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => $conversation,
        // A plausible id that was never proposed in this conversation.
        'decisions' => ['call_from_another_run' => true],
    ])->assertForbidden();

    // And the genuine approval is still available afterwards.
    $this->actingAs($this->officer)->postJson(route('finance.approvals.pending'), [
        'conversation_id' => $conversation,
    ])->assertOk()->assertJsonCount(1, 'data.pending');
});

it('refuses to approve a tool outside the allowlist', function (): void {
    $request = escalatedRequest();

    SettlementOperator::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval(
                id: 'call_other',
                tool: 'RecordHardshipContext',
                arguments: ['note' => 'x'],
                reason: 'anything',
            ),
        ]),
    ]);

    $paused = SettlementOperator::make()->forUser($this->officer)->prompt('Annotate.');

    $this->actingAs($this->officer)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => $paused->conversationId,
        'decisions' => ['call_other' => true],
    ])->assertForbidden();

    expect($request->fresh()->status)->toBe(AssistanceStatus::SUBMITTED);
});

it('treats a replayed approval as inert', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    SettlementOperator::fake(['Resumed.']);

    $this->actingAs($this->officer)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => $conversation,
        'decisions' => ['call_abc' => true],
    ])->assertOk();

    // The same request again must find nothing paused, not pay twice.
    $this->actingAs($this->officer)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => $conversation,
        'decisions' => ['call_abc' => true],
    ])->assertOk()
        ->assertJsonPath('data.status', 'nothing_pending')
        ->assertJsonPath('success', false);
});

it('refuses to resume while settlement proposals are switched off', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    // The operator turned proposals off after the run paused.
    $settings = app(AiSettings::class);
    $settings->allow_settlement_proposals = false;
    $settings->save();
    app()->forgetInstance(AiSettings::class);

    $provider = app(AiProvider::class)->first();
    $provider->update(['is_active' => false]);
    app()->forgetInstance(AiProviderResolver::class);

    $this->actingAs($this->officer)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => $conversation,
        'decisions' => ['call_abc' => true],
    ])->assertForbidden();
});

it('requires an authenticated verified user', function (): void {
    $this->postJson(route('finance.approvals.resume'), [
        'conversation_id' => (string) Str::uuid7(),
        'decisions' => ['call_abc' => true],
    ])->assertUnauthorized();
});

it('rejects a payload carrying anything but id to bool', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    $this->actingAs($this->officer)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => $conversation,
        // No amount, recipient or arguments field exists to be smuggled through.
        'decisions' => ['call_abc' => 'yes'],
    ])->assertUnprocessable();
});

it('rejects an empty decision map', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    $this->actingAs($this->officer)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => $conversation,
        'decisions' => [],
    ])->assertUnprocessable();
});

it('requires a valid conversation id shape', function (): void {
    $this->actingAs($this->officer)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => 'not-a-uuid',
        'decisions' => ['call_abc' => true],
    ])->assertUnprocessable();
});

it('rejects a rejection reason being treated as an approval', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    SettlementOperator::fake(['Resumed.']);

    $response = $this->actingAs($this->officer)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => $conversation,
        'decisions' => ['call_abc' => false],
    ]);

    $response->assertOk()
        ->assertJsonPath('data.rejected.0', 'call_abc');

    expect(Approval::where('approver_id', $this->officer->id)->exists())->toBeFalse()
        ->and($request->fresh()->status)->toBe(AssistanceStatus::SUBMITTED);
});

it('exposes the gate so a route added later inherits the checks', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    // Calling the gate directly, with no controller and no middleware, still
    // refuses. The checks live in the gate rather than at the edge.
    $other = reviewer('officer2@eduflow.test');

    expect(fn () => app(ApprovalResumeGate::class)->pendingFor($other, $conversation))
        ->toThrow(AuthorizationException::class);
});

it('does not attribute a rejection as an approval', function (): void {
    $request = escalatedRequest();
    $conversation = pausedConversation($this->officer, $request);

    SettlementOperator::fake(['Resumed.']);

    $this->actingAs($this->officer)->postJson(route('finance.approvals.resume'), [
        'conversation_id' => $conversation,
        'decisions' => ['call_abc' => false],
    ])->assertOk();

    // A refused proposal leaves no approval record claiming a human released it.
    expect(Approval::where('approver_id', $this->officer->id)->where('status', 'approved')->exists())->toBeFalse()
        ->and($request->fresh()->status)->toBe(AssistanceStatus::SUBMITTED);
});

it('refuses to resume when the operator has no persisted organization', function (): void {
    // The container would hand back a blank Organization whose primaryWallet()
    // is null, so the tool would refuse for the wrong reason. The factory
    // reports the actual problem instead.
    Organization::query()->delete();

    expect(SettlementOperatorFactory::make())->toBeNull()
        ->and(fn () => SettlementOperatorFactory::makeOrFail())
        ->toThrow(RuntimeException::class, 'needs a persisted organization');
});

it('builds an operator bound to real records', function (): void {
    $operator = SettlementOperatorFactory::makeOrFail();

    $reflection = new ReflectionObject($operator);

    foreach (['organization', 'fund', 'policyVersion'] as $property) {
        $value = $reflection->getProperty($property)->getValue($operator);

        expect($value->exists)->toBeTrue("{$property} must be a persisted record")
            ->and($value->getKey())->not->toBeNull();
    }
});
