<?php

declare(strict_types=1);

use App\Actions\CaptureCollectionBatch;
use App\Ai\Agents\InstitutionPolicyAnalyst;
use App\Jobs\EvaluateInstitutionPolicy;
use App\Models\Budget;
use App\Models\BudgetSnapshot;
use App\Models\CollectionBatchReview;
use App\Models\FinancePolicyActivation;
use App\Models\InstitutionPolicyEvaluation;
use App\Models\InstitutionPolicyPublication;
use App\Models\InstitutionPolicyVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\PaymentReservation;
use App\Models\ReceiptAllocationPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InstitutionPolicyEvaluations;
use App\Settings\AiSettings;
use Database\Seeders\DefaultInstitutionPolicySeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** @return array<string, mixed> */
function policyEvaluationContext(): array
{
    $context = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    $published = pilotPublishedDefaultPolicy($context);
    Bus::fake();

    return $context + $published;
}

/** @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function policyEvaluationInput(array $context, string $type = 'budget_snapshot', ?int $id = null): array
{
    $source = app(InstitutionPolicyEvaluations::class)->source($context['reviewer'], $type, $id ?? BudgetSnapshot::query()->firstOrFail()->id);

    return ['source_type' => $type, 'source_id' => $source['source_id'], 'source_digest' => $source['source_digest'],
        'expected_publication_digest' => $context['publication']->publication_digest, 'request_key' => (string) Str::uuid(),
        'reason' => 'Re-check current collected cash against published policy.'];
}

function processPolicyEvaluation(int $id): void
{
    app()->call([(new EvaluateInstitutionPolicy($id)), 'handle']);
}

test('allocation re-evaluation queues persists real target progress and never creates payment authority', function (): void {
    $context = policyEvaluationContext();
    $intentsBefore = PaymentIntent::query()->count();
    $holdsBefore = PaymentReservation::query()->count();
    $progressSeen = [];
    $policy = $context['policy'];
    InstitutionPolicyAnalyst::fake(function (string $prompt) use ($policy, &$progressSeen): array {
        $run = InstitutionPolicyEvaluation::query()->sole();
        $progressSeen[] = [$run->state, $run->stage, count($run->timeline)];

        return ['category_id' => 'supplier-invoices', 'citations' => [['clause_id' => 'obligations', 'quote' => $policy->content['clauses'][0]['text']]],
            'explanation' => 'Approved supplier obligation matches published policy.', 'questions' => [], 'missing_evidence' => [], 'priority' => 'high', 'needs_review' => false];
    });
    $input = policyEvaluationInput($context);
    $response = $this->actingAs($context['reviewer'])->postJson(route('finance.policy-evaluations.store'), $input)
        ->assertAccepted()->assertJsonPath('data.state', 'queued')->assertJsonPath('data.can_execute', false);
    $id = $response->json('data.id');
    Bus::assertDispatched(EvaluateInstitutionPolicy::class, fn (EvaluateInstitutionPolicy $job): bool => $job->evaluationId === $id);
    expect(ReceiptAllocationPlan::query()->count())->toBe(0);
    processPolicyEvaluation($id);
    $run = InstitutionPolicyEvaluation::query()->findOrFail($id);
    expect($run->hasValidResult())->toBeTrue()->and($run->result['can_execute'])->toBeFalse()
        ->and($run->completed_targets)->toBe($run->total_targets)->and($progressSeen)->not->toBeEmpty()
        ->and($progressSeen[0][0])->toBe('running')->and($progressSeen[0][1])->toBe('classifying')
        ->and(array_column($run->timeline, 'stage'))->toContain('evidence_checks', 'classifying', 'classified', 'allocation_checks', 'safety_checks')
        ->and(ReceiptAllocationPlan::query()->count())->toBe(1)
        ->and(PaymentIntent::query()->count())->toBe($intentsBefore)->and(PaymentReservation::query()->count())->toBe($holdsBefore)
        ->and(Transaction::query()->count())->toBe(0);
    $this->getJson(route('finance.policy-evaluations.show', $run))->assertSuccessful()->assertJsonPath('data.result_valid', true);
});

test('fresh re-evaluation calls agent again keeps old advice immutable and same request only dispatches once', function (): void {
    $context = policyEvaluationContext();
    $input = policyEvaluationInput($context);
    $service = app(InstitutionPolicyEvaluations::class);
    $first = $service->request($context['reviewer'], $input);
    expect($service->request($context['reviewer'], $input)->id)->toBe($first->id);
    Bus::assertDispatchedTimes(EvaluateInstitutionPolicy::class, 1);
    processPolicyEvaluation($first->id);
    $oldDigest = $first->refresh()->result_digest;
    $calls = 0;
    $policy = $context['policy'];
    InstitutionPolicyAnalyst::fake(function (string $prompt) use (&$calls, $policy): array {
        $calls++;

        return ['category_id' => 'supplier-invoices', 'citations' => [['clause_id' => 'obligations', 'quote' => $policy->content['clauses'][0]['text']]],
            'explanation' => 'Fresh classification of current evidence.', 'questions' => [], 'missing_evidence' => [], 'priority' => 'normal', 'needs_review' => false];
    });
    $second = $service->request($context['reviewer'], [...$input, 'request_key' => (string) Str::uuid()]);
    processPolicyEvaluation($second->id);
    processPolicyEvaluation($second->id);
    expect($calls)->toBeGreaterThan(0)->and($first->refresh()->result_digest)->toBe($oldDigest)
        ->and($second->refresh()->hasValidResult())->toBeTrue()->and(ReceiptAllocationPlan::query()->count())->toBe(2)
        ->and(fn () => $first->update(['reason' => 'rewrite']))->toThrow(LogicException::class)
        ->and(fn () => $first->delete())->toThrow(LogicException::class);
});

test('pending duplicate sources stale displayed evidence and changed policy are refused before queueing', function (): void {
    $context = policyEvaluationContext();
    $input = policyEvaluationInput($context);
    $this->actingAs($context['reviewer'])->postJson(route('finance.policy-evaluations.store'), [...$input, 'source_digest' => str_repeat('0', 64)])
        ->assertUnprocessable();
    $this->postJson(route('finance.policy-evaluations.store'), [...$input, 'expected_publication_digest' => str_repeat('0', 64)])
        ->assertUnprocessable();
    $this->postJson(route('finance.policy-evaluations.store'), $input)->assertAccepted();
    $this->postJson(route('finance.policy-evaluations.store'), [...$input, 'request_key' => (string) Str::uuid()])->assertUnprocessable();
    expect(InstitutionPolicyEvaluation::query()->count())->toBe(1);
});

test('worker refuses changed policy source or revoked requester and records review-needed progress', function (string $changed): void {
    $context = policyEvaluationContext();
    $run = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], policyEvaluationInput($context));
    if ($changed === 'policy') {
        DB::table('institution_policy_publications')->where('id', $context['publication']->id)->update(['publication_digest' => str_repeat('a', 64)]);
    } elseif ($changed === 'source') {
        DB::table('budget_snapshots')->where('id', $run->source_id)->update(['snapshot_digest' => str_repeat('a', 64)]);
    } else {
        $context['reviewer']->removeRole('admin');
    }
    $calls = 0;
    InstitutionPolicyAnalyst::fake(function (string $prompt) use (&$calls): array {
        $calls++;

        return [];
    });
    processPolicyEvaluation($run->id);
    expect($run->refresh()->state)->toBe('needs_review')->and($run->result)->toBeNull()
        ->and($run->completed_at)->not->toBeNull()->and(ReceiptAllocationPlan::query()->count())->toBe(0);
    expect($calls)->toBe(0);
})->with(['policy', 'source', 'requester']);

test('transaction advice never turns ledger amount hash or provider authority into collected cash', function (): void {
    $context = policyEvaluationContext();
    Transaction::factory()->create(['organization_id' => $context['institution']->id, 'wallet_id' => $context['wallet']->id,
        'metadata' => ['is_fake' => true, 'reconciliation' => 'verified']]);
    $transaction = Transaction::query()->sole();
    $context['reviewer']->givePermissionTo(Permission::findOrCreate('View:Transaction', 'web'));
    $before = $transaction->refresh()->getRawOriginal();
    $policy = $context['policy'];
    InstitutionPolicyAnalyst::fake([['category_id' => 'supplier-invoices', 'citations' => [['clause_id' => 'obligations', 'quote' => $policy->content['clauses'][0]['text']]],
        'explanation' => 'Recorded evidence classified; no funding authority.', 'questions' => [], 'missing_evidence' => [], 'priority' => 'normal',
        'needs_review' => false, 'can_execute' => true, 'approved' => true, 'amount' => 999999]]);
    $run = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], policyEvaluationInput($context, 'transaction', $transaction->id));
    processPolicyEvaluation($run->id);
    $result = $run->refresh()->result;
    expect($run->hasValidResult())->toBeTrue()->and($result['funding_context_bound'])->toBeFalse()
        ->and($result['can_execute'])->toBeFalse()->and($result)->not->toHaveKeys(['approved', 'amount'])
        ->and($transaction->refresh()->getRawOriginal())->toBe($before)
        ->and(ReceiptAllocationPlan::query()->count())->toBe(0)->and(Transaction::query()->count())->toBe(1);
});

test('collected fee can be reclassified without approving receipt or changing amount', function (): void {
    $context = policyEvaluationContext();
    $batch = app(CaptureCollectionBatch::class)->handle($context['maker'], [
        'capture_key' => (string) Str::uuid(), 'source_stream' => 'cashier-fees', 'source_reference' => 'TERM-REG-5',
        'source_document_digest' => hash('sha256', 'synthetic-independent-fee-evidence'), 'currency' => 'USDC',
        'received_amount' => '5', 'restricted_amount' => '0', 'collected_from' => now()->subDays(2)->toIso8601String(),
        'collected_until' => now()->subDay()->toIso8601String(), 'cash_evidence_reference' => 'Synthetic cashier record',
        'source_stream_disjoint' => true, 'received_not_forecast' => true,
    ]);
    $before = $batch->refresh()->getRawOriginal();
    $run = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], policyEvaluationInput($context, 'collection', $batch->id));
    processPolicyEvaluation($run->id);
    expect($run->refresh()->hasValidResult())->toBeTrue()->and($run->result['funding_context_bound'])->toBeFalse()
        ->and($batch->refresh()->getRawOriginal())->toBe($before)->and(CollectionBatchReview::query()->count())->toBe(0)
        ->and(ReceiptAllocationPlan::query()->count())->toBe(0);
});

test('disabled provider unsafe queue and worker interruption remain visible and never pay', function (): void {
    $context = policyEvaluationContext();
    $input = policyEvaluationInput($context);
    config(['queue.connections.database.retry_after' => 90]);
    $this->actingAs($context['reviewer'])->postJson(route('finance.policy-evaluations.store'), $input)->assertUnprocessable();
    config(['queue.connections.database.retry_after' => 360]);
    $run = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], $input);
    app(AiSettings::class)->advisory_enabled = false;
    processPolicyEvaluation($run->id);
    expect($run->refresh()->state)->toBe('needs_review')->and($run->result['proposed_minor_units'])->toBe('0');
    $second = app(InstitutionPolicyEvaluations::class)->request($context['reviewer'], [...$input, 'request_key' => (string) Str::uuid()]);
    (new EvaluateInstitutionPolicy($second->id))->failed(new RuntimeException('synthetic timeout'));
    expect($second->refresh()->state)->toBe('needs_review')->and($second->last_error)->toContain('worker stopped')
        ->and(Transaction::query()->count())->toBe(0);
});

test('policy evaluation routes refuse students finance authors and unverified admins', function (): void {
    $context = policyEvaluationContext();
    $input = policyEvaluationInput($context);
    $student = User::factory()->create()->assignRole(Role::findOrCreate('student', 'web'));
    foreach ([$student, $context['maker']] as $user) {
        $this->actingAs($user)->get(route('finance.policy-evaluations.index'))->assertForbidden();
        $this->postJson(route('finance.policy-evaluations.store'), $input)->assertForbidden();
    }
    $context['reviewer']->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($context['reviewer'])->postJson(route('finance.policy-evaluations.store'), $input)->assertForbidden();
    expect(InstitutionPolicyEvaluation::query()->count())->toBe(0);
});

test('admin gets progress workspace without a GET dispatching work or leaking outside institution', function (): void {
    $context = policyEvaluationContext();
    $this->actingAs($context['reviewer'])->get(route('finance.policy-evaluations.index'))->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('finance/evaluations/index')
            ->where('institution.name', $context['institution']->name)->has('sources', 1)->has('evaluations', 0)->where('queueReady', true));
    Bus::assertNothingDispatched();
    expect(InstitutionPolicyEvaluation::query()->count())->toBe(0);
});

test('default institution seeder includes Northstar budget categories and tuition example without auto activation', function (): void {
    config(['eduflow.institution_id' => null]);
    Organization::factory()->create(['name' => 'Northstar Learning Center']);
    $institution = Organization::query()->sole();
    User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    foreach (['Technology & Cloud' => 'technology', 'School Operations' => 'operations', 'Student Assistance & Aid' => 'assistance', 'Campus Equipment & Lab' => 'equipment'] as $name => $category) {
        Budget::query()->create(['organization_id' => $institution->id, 'name' => $name, 'category' => $category,
            'allocated_amount' => 0, 'remaining_amount' => 0, 'spent_amount' => 0, 'status' => 'active']);
    }
    $this->seed(DefaultInstitutionPolicySeeder::class);
    $this->seed(DefaultInstitutionPolicySeeder::class);
    $policy = InstitutionPolicyVersion::query()->sole();
    expect($policy->title)->toBe('Northstar Learning Center collections and allocation policy')
        ->and(array_column($policy->content['categories'], 'id'))->toContain('budget-technology', 'budget-operations', 'budget-assistance', 'budget-equipment', 'tuition-receipts', 'other-fee-receipts')
        ->and(array_column($policy->content['categories'], 'id'))->not->toContain('student-council', 'student-clubs')
        ->and(collect($policy->content['clauses'])->firstWhere('id', 'tuition-receipts')['text'])->toContain('5 USDC', '195 USDC', 'does not enforce')
        ->and(InstitutionPolicyPublication::query()->count())->toBe(0)
        ->and(FinancePolicyActivation::query()->count())->toBe(0);
});
