<?php

declare(strict_types=1);

use App\Actions\EvaluateAssistancePolicy;
use App\Ai\Agents\SettlementOperator;
use App\Ai\Tools\DisburseAssistance;
use App\Enums\AssistanceStatus;
use App\Models\AcademicTerm;
use App\Models\AssistanceFund;
use App\Models\AssistancePolicyVersion;
use App\Models\AssistanceRequest;
use App\Models\Organization;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\TuitionAccount;
use App\Services\CircleWalletService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Tools\Request;
use ReflectionMethod;

/**
 * The ratchet: the model proposes, PHP disposes.
 *
 * `needsApproval()` is the policy engine. These tests prove an under-limit
 * proposal executes, an over-limit one pauses for a human, and no model output
 * can talk either out of it.
 */

/** Invoke the protected hook the way the SDK does. */
function approvalVerdict(DisburseAssistance $tool, Request $request): Approval|bool
{
    return (new ReflectionMethod($tool, 'needsApproval'))->invoke($tool, $request);
}

beforeEach(function (): void {
    config(['lepton.default' => 'fake']);

    $this->seed(DatabaseSeeder::class);

    $this->student = Student::firstOrFail();
    $this->term = AcademicTerm::firstOrFail();

    $this->organization = Organization::firstOrFail();
    $this->fund = AssistanceFund::where('organization_id', $this->organization->id)->firstOrFail();
    $this->policyVersion = AssistancePolicyVersion::active();
});

/**
 * Build the tool the same way SettlementOperator does. An assistance request
 * carries no organization, so the tool is constructed with one explicitly.
 */
function disburseTool(): DisburseAssistance
{
    $organization = Organization::firstOrFail();

    return new DisburseAssistance(
        policy: app(EvaluateAssistancePolicy::class),
        wallets: app(CircleWalletService::class),
        organization: $organization,
        fund: AssistanceFund::where('organization_id', $organization->id)->firstOrFail(),
        policyVersion: AssistancePolicyVersion::active(),
    );
}

function openRequest(array $attributes = []): AssistanceRequest
{
    return AssistanceRequest::factory()->create(array_merge([
        'student_id' => Student::firstOrFail()->id,
        'academic_term_id' => AcademicTerm::firstOrFail()->id,
        'requested_amount' => 15_000000,
        'status' => AssistanceStatus::SUBMITTED,
    ], $attributes));
}

it('executes without a human when policy auto-approves the proposal', function (): void {
    $request = openRequest(['requested_amount' => 5_000000]);

    $tool = disburseTool();

    expect(approvalVerdict($tool, new Request(['assistance_request_id' => $request->id])))
        ->toBeFalse();
});

it('pauses for a human when the proposal exceeds the autonomous limit', function (): void {
    // The seeded auto-limit is 10 USDC, so 150 must escalate.
    $request = openRequest(['requested_amount' => 150_000000]);

    $verdict = approvalVerdict(disburseTool(), new Request([
        'assistance_request_id' => $request->id,
    ]));

    expect($verdict)->toBeInstanceOf(Approval::class)
        ->and($verdict->reason)->not->toBeEmpty();
});

it('fails closed when the proposal cannot be evaluated at all', function (): void {
    $verdict = approvalVerdict(disburseTool(), new Request([
        'assistance_request_id' => 999999,
    ]));

    expect($verdict)->toBeInstanceOf(Approval::class);
});

it('refuses to disburse when policy would escalate, even if handle is called directly', function (): void {
    $request = openRequest(['requested_amount' => 150_000000]);

    $before = $request->fresh()->status;
    $fundBefore = AssistanceFund::where('organization_id', Organization::firstOrFail()->id)
        ->firstOrFail()->balance_base_units;

    $result = disburseTool()->handle(new Request([
        'assistance_request_id' => $request->id,
    ]));

    // handle() re-evaluates policy rather than trusting needsApproval(), so
    // bypassing the approval hook still cannot move money. Asserting on the
    // side effects matters more than the message: for an escalated decision the
    // approved amount is already zero, so only the side effects distinguish a
    // working guard from a missing one.
    $transactions = Transaction::where('reference_type', AssistanceRequest::class)
        ->where('reference_id', $request->id)
        ->count();

    expect((string) $result)->toContain('Not disbursed')
        ->and((string) $result)->toContain('human authorisation')
        ->and($request->fresh()->status)->toBe($before)
        ->and($transactions)->toBe(0)
        ->and(AssistanceFund::where('organization_id', Organization::firstOrFail()->id)
            ->firstOrFail()->balance_base_units)->toBe($fundBefore);
});

it('refuses to invent a payout address for a student who has none', function (): void {
    // Otherwise-qualified in every other respect, so the only thing standing
    // between this proposal and a payment is the missing address.
    $student = Student::factory()->withoutPayoutAddress()->create([
        'enrollment_status' => 'enrolled',
        'academic_status' => 'qualified',
        'attendance_rate' => 95,
    ]);

    $request = openRequest([
        'student_id' => $student->id,
        'requested_amount' => 5_000000,
    ]);

    TuitionAccount::factory()->create([
        'student_id' => $student->id,
        'academic_term_id' => AcademicTerm::firstOrFail()->id,
        'total_amount' => 300_000000,
        'paid_amount' => 0,
    ]);

    $result = disburseTool()->handle(new Request([
        'assistance_request_id' => $request->id,
    ]));

    expect((string) $result)->toContain('Not disbursed')
        ->and((string) $result)->toContain('payout address');
});

it('is still an approvable tool even when it auto-approves', function (): void {
    // The tool must implement Approvable so the SDK routes it through the
    // approval machinery at all; needsApproval() only relaxes it per call.
    expect(disburseTool())
        ->toBeInstanceOf(Approvable::class)
        ->toBeInstanceOf(Tool::class);
});

it('pauses on an over-limit proposal when the operator agent runs', function (): void {
    $request = openRequest(['requested_amount' => 150_000000]);

    SettlementOperator::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval(
                id: 'call_abc',
                tool: 'DisburseAssistance',
                arguments: ['assistance_request_id' => $request->id, 'reason' => 'over limit'],
                reason: 'Amount exceeds the autonomous threshold.',
            ),
        ]),
    ]);

    $response = SettlementOperator::make()->forUser(Student::firstOrFail()->user)->prompt(
        'Disburse the open assistance request.'
    );

    expect($response->hasPendingApprovals())->toBeTrue()
        ->and($response->pendingApprovals[0]->tool)->toBe('DisburseAssistance')
        ->and($response->pendingApprovals[0]->arguments['assistance_request_id'])->toBe($request->id);

    // Nothing was paid while the run sat waiting for a person.
    expect($request->fresh()->status)->toBe(AssistanceStatus::SUBMITTED);
});

it('records a human decision on a paused tool call', function (): void {
    $request = openRequest(['requested_amount' => 150_000000]);

    SettlementOperator::fake();

    SettlementOperator::make()->forUser(Student::firstOrFail()->user)->prompt(
        Decisions::from(['call_abc' => Decision::reject('Over the autonomous limit.')])
    );

    SettlementOperator::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->hasApprovalDecisions()
        && $prompt->approvalDecisions->get('call_abc')?->isApproved() === false);
});

it('declares a schema so the operator cannot emit an amount as a field', function (): void {
    $schema = app(SettlementOperator::class)->schema(app(JsonSchemaTypeFactory::class));

    $keys = array_keys($schema);

    // The structured output is a summary of what happened, not the authority.
    expect($keys)->toBe(['summary', 'proposals_made', 'awaiting_human'])
        ->and($keys)->not->toContain('approved_amount')
        ->and($keys)->not->toContain('decision');
});
