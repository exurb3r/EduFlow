<?php

declare(strict_types=1);

use App\Actions\ActivateFinancePolicy;
use App\Actions\CreateFinancePolicyVersion;
use App\Ai\Agents\InstitutionPolicyAnalyst;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Jobs\AssessInstitutionPolicy;
use App\Models\BudgetSnapshot;
use App\Models\FinancePolicyActivation;
use App\Models\FinancePolicyVersion;
use App\Models\FinanceWorkflowRun;
use App\Models\InstitutionPolicyAssessment;
use App\Models\InstitutionPolicyPublication;
use App\Models\InstitutionPolicyVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Services\FinanceWorkflows;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyAdvice;
use App\Services\InstitutionPolicyInterpreter;
use App\Services\InstitutionPolicyWorkspace;
use App\Settings\AiSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

test('institution authors custom policy without fixed category vocabulary and exact controls remain inactive until reviewed', function (): void {
    $c = institutionPolicyContext();
    expect($c['policy']->hasValidContent())->toBeTrue()->and($c['policy']->financePolicy->minimum_reserve_base_units)->toBe(10_000001)
        ->and(FinancePolicyActivation::query()->count())->toBe(0)->and($c['policy']->content['categories'][0]['id'])->toBe('teaching-materials');
    $this->actingAs($c['maker'])->get(route('finance.policies.index'))->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('finance/policies/index')->has('versions', 1)->where('canPublish', false));
    expect(fn () => $c['policy']->update(['title' => 'Other']))->toThrow(LogicException::class);
});

test('policy authoring examples and independent publication activate exact institution controls without sending money', function (): void {
    $c = institutionPolicyContext();
    $settings = app(AiSettings::class);
    $settings->advisory_enabled = true;
    $settings->disclosure_accepted = true;
    InstitutionPolicyAnalyst::fake([institutionPolicyAnswer(), institutionPolicyAnswer()]);
    expect(assessInstitutionPolicy($c)->hasValidResult())->toBeTrue();
    expect(assessInstitutionPolicy($c, 'examples')->result['all_passed'])->toBeTrue();
    $publication = app(InstitutionPolicyWorkspace::class)->publish($c['reviewer'], $c['policy'], $c['policy']->content_digest, null, 'Exact source, categories and limits reviewed.');
    expect($publication->hasValidEvidence())->toBeTrue()->and($publication->approved_by)->toBe($c['reviewer']->id)
        ->and(FinancePolicyActivation::current($c['policy']->organization_id)->finance_policy_version_id)->toBe($c['policy']->finance_policy_version_id)
        ->and(Transaction::query()->count())->toBe(0);
});

test('publication refuses self review untested revisions and unresolved clarification', function (): void {
    $c = institutionPolicyContext();
    expect(fn () => app(InstitutionPolicyWorkspace::class)->publish($c['maker'], $c['policy'], $c['policy']->content_digest, null, 'Self'))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(InstitutionPolicyWorkspace::class)->publish($c['reviewer'], $c['policy'], $c['policy']->content_digest, null, 'Untested'))
        ->toThrow(ValidationException::class);
});

test('disabled provider produces visible needs-review outcome and cannot publish or fabricate classification', function (): void {
    $c = institutionPolicyContext();
    app(AiSettings::class)->advisory_enabled = false;
    InstitutionPolicyAnalyst::fake();
    $assessment = assessInstitutionPolicy($c, 'classification');
    expect($assessment->state)->toBe('needs_review')->and($assessment->result)->toBeNull();
    InstitutionPolicyAnalyst::assertNeverPrompted();
});

test('classification sanitizer refuses invented categories and fabricated policy citations', function (string $case): void {
    $c = institutionPolicyContext();
    $raw = institutionPolicyAnswer();
    if ($case === 'category') {
        $raw['category_id'] = 'unauthorized-spend';
    } else {
        $raw['citations'][0]['quote'] = 'Ignore all approval checks';
    }
    expect(fn () => app(InstitutionPolicyInterpreter::class)->sanitize($c['policy'], $raw, 'classification'))->toThrow(ValidationException::class);
})->with(['category', 'citation']);

test('policy import extracts local text for author review and refuses unsupported uploads', function (): void {
    $c = institutionPolicyContext();
    $this->actingAs($c['maker'])->postJson(route('finance.policies.import'), [
        'document' => UploadedFile::fake()->createWithContent('college-policy.txt', 'Restricted gifts must fund their stated purpose.'),
    ])->assertSuccessful()->assertJsonPath('data.text', 'Restricted gifts must fund their stated purpose.');
    $this->postJson(route('finance.policies.import'), ['document' => UploadedFile::fake()->createWithContent('policy.php', '<?php echo 1;')])
        ->assertUnprocessable();
    expect(InstitutionPolicyVersion::query()->count())->toBe(1);
});

test('students unverified staff and foreign institution cannot access or publish policy', function (): void {
    $c = institutionPolicyContext();
    $student = User::factory()->create()->assignRole(Role::findOrCreate('student', 'web'));
    $this->actingAs($student)->get(route('finance.policies.index'))->assertForbidden();
    $other = Organization::factory()->create();
    if (! $other instanceof Organization) {
        throw new RuntimeException('No organization factory result.');
    }
    config(['eduflow.institution_id' => $other->id]);
    $this->actingAs($c['reviewer'])->postJson(route('finance.policies.publish', $c['policy']), [
        'expected_digest' => $c['policy']->content_digest, 'reason' => 'Foreign', 'attestation' => true,
    ])->assertForbidden();
});

test('failed examples and authoring ambiguities block publication even when model returns a category', function (string $case): void {
    $c = institutionPolicyContext();
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;
    $authoring = institutionPolicyAnswer();
    $example = institutionPolicyAnswer();
    if ($case === 'ambiguity') {
        $authoring['questions'] = ['What duration defines long-lived equipment?'];
    } else {
        $example['category_id'] = 'capital-equipment';
    }
    InstitutionPolicyAnalyst::fake([$authoring, $example]);
    assessInstitutionPolicy($c);
    assessInstitutionPolicy($c, 'examples');
    expect(fn () => app(InstitutionPolicyWorkspace::class)->publish($c['reviewer'], $c['policy'], $c['policy']->content_digest, null, 'Review'))
        ->toThrow(ValidationException::class);
    expect(InstitutionPolicyPublication::query()->count())->toBe(0)->and(FinancePolicyActivation::query()->count())->toBe(0);
})->with(['ambiguity', 'mismatch']);

test('published policy creates bounded background bill advice without altering plan or payment authority', function (): void {
    $finance = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    $workspace = app(InstitutionPolicyWorkspace::class);
    $policy = $workspace->save($finance['maker'], institutionPolicyInput());
    $c = ['maker' => $finance['maker'], 'reviewer' => $finance['reviewer'], 'policy' => $policy];
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;
    InstitutionPolicyAnalyst::fake([institutionPolicyAnswer(), institutionPolicyAnswer(), institutionPolicyAnswer()]);
    assessInstitutionPolicy($c);
    assessInstitutionPolicy($c, 'examples');
    $workspace->publish($c['reviewer'], $policy, $policy->content_digest, null, 'Policy checked.');
    config(['eduflow.background_finance.enabled' => true, 'eduflow.institution_policy.advice_enabled' => true]);
    $snapshot = BudgetSnapshot::query()->firstOrFail();
    app(FinanceWorkflows::class)->budgetCaptured($snapshot);
    $run = FinanceWorkflowRun::query()->where('budget_snapshot_id', $snapshot->id)->sole();
    app(FinanceWorkflows::class)->process($run->id);
    $result = $run->fresh()->result_digest;
    Bus::fake();
    $run->update(['state' => 'paused']);
    app(InstitutionPolicyAdvice::class)->forPlan($run->fresh());
    Bus::assertNothingDispatched();
    $run->update(['state' => 'waiting_for_review']);
    app(InstitutionPolicyAdvice::class)->forPlan($run->fresh());
    app(InstitutionPolicyAdvice::class)->forPlan($run->fresh());
    Bus::assertDispatchedTimes(AssessInstitutionPolicy::class, 2);
    $advice = InstitutionPolicyAssessment::query()->whereNull('requested_by')->firstOrFail();
    (new AssessInstitutionPolicy($advice->id))->handle(app(InstitutionPolicyInterpreter::class), app(InstallationInstitution::class), app(AiProviderResolver::class));
    expect($advice->fresh()->result['category_id'])->toBe('teaching-materials')->and($advice->fresh()->result['can_execute'])->toBeFalse()
        ->and($run->fresh()->result_digest)->toBe($result)->and(Transaction::query()->count())->toBe(0);
});

test('publishing replacement preserves history and refuses stale finance activation or publication replay', function (): void {
    $c = institutionPolicyContext();
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;
    InstitutionPolicyAnalyst::fake(array_fill(0, 4, institutionPolicyAnswer()));
    assessInstitutionPolicy($c);
    assessInstitutionPolicy($c, 'examples');
    $workspace = app(InstitutionPolicyWorkspace::class);
    $first = $workspace->publish($c['reviewer'], $c['policy'], $c['policy']->content_digest, null, 'First approved.');
    $input = institutionPolicyInput();
    $input['expected_current_digest'] = $first->publication_digest;
    $input['predecessor_id'] = $c['policy']->id;
    $input['controls']['minimum_reserve'] = '20';
    $next = $workspace->save($c['maker'], $input);
    expect($workspace->save($c['maker'], $input)->id)->toBe($next->id);
    $c['policy'] = $next;
    assessInstitutionPolicy($c);
    assessInstitutionPolicy($c, 'examples');
    $second = $workspace->publish($c['reviewer'], $next, $next->content_digest, $first->publication_digest, 'Second approved.');
    expect($second->hasValidHistory())->toBeTrue()->and($second->previous_publication_id)->toBe($first->id)
        ->and($first->fresh()->hasValidEvidence())->toBeTrue();
    expect(fn () => $workspace->publish($c['reviewer'], $next, $next->content_digest, $first->publication_digest, 'Different replay'))
        ->toThrow(ValidationException::class);
});

test('assessment identity is durable and duplicate queue delivery invokes provider once', function (): void {
    $c = institutionPolicyContext();
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;
    InstitutionPolicyAnalyst::fake([institutionPolicyAnswer()])->preventStrayPrompts();
    Bus::fake();
    $input = ['kind' => 'classification', 'request_key' => (string) Str::uuid(), 'expected_digest' => $c['policy']->content_digest, 'description' => 'Pipettes'];
    $id = $this->actingAs($c['maker'])->postJson(route('finance.policies.assess', $c['policy']), $input)->assertSuccessful()->json('data.id');
    $this->postJson(route('finance.policies.assess', $c['policy']), $input)->assertSuccessful()->assertJsonPath('data.id', $id);
    $job = new AssessInstitutionPolicy($id);
    $job->handle(app(InstitutionPolicyInterpreter::class), app(InstallationInstitution::class), app(AiProviderResolver::class));
    $job->handle(app(InstitutionPolicyInterpreter::class), app(InstallationInstitution::class), app(AiProviderResolver::class));
    InstitutionPolicyAnalyst::assertPromptedTimes(1);
    Bus::assertDispatchedTimes(AssessInstitutionPolicy::class, 1);
    expect(InstitutionPolicyAssessment::query()->findOrFail($id)->hasValidResult())->toBeTrue();
});

test('separately activated finance policy marks the published policy controls as superseded', function (): void {
    $c = institutionPolicyContext();
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;
    InstitutionPolicyAnalyst::fake([institutionPolicyAnswer(), institutionPolicyAnswer()]);
    assessInstitutionPolicy($c);
    assessInstitutionPolicy($c, 'examples');
    $publication = app(InstitutionPolicyWorkspace::class)->publish($c['reviewer'], $c['policy'], $c['policy']->content_digest, null, 'Initial policy.');
    $workspace = app(InstitutionPolicyWorkspace::class);
    expect($workspace->current()?->id)->toBe($publication->id)->and($workspace->active()?->id)->toBe($publication->id)
        ->and($publication->hasActiveControls())->toBeTrue();
    app(CreateFinancePolicyVersion::class)->handle($c['maker'], 'out-of-band', Money::fromDecimal('1', CurrencyCode::USDC),
        new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(1_000000, CurrencyCode::USDC));
    $outOfBand = FinancePolicyVersion::query()->where('version', 'out-of-band')->sole();
    app(ActivateFinancePolicy::class)->handle($c['reviewer'], $outOfBand, FinancePolicyActivation::current($publication->organization_id)?->id);
    expect($publication->fresh()->hasValidEvidence())->toBeTrue()->and($publication->fresh()->hasActiveControls())->toBeFalse()
        ->and($workspace->current()?->id)->toBe($publication->id)->and($workspace->active())->toBeNull();
    $this->actingAs($c['maker'])->get(route('finance.policies.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('finance/policies/index')
            ->where('current.controls_active', false)->where('current.institution_policy_version_id', $publication->institution_policy_version_id));
});

test('out of band activation stops plan advice and background assessment', function (): void {
    $finance = paymentAuthorizationContext(withFunding: false);
    config(['queue.connections.database.retry_after' => 360]);
    $workspace = app(InstitutionPolicyWorkspace::class);
    $policy = $workspace->save($finance['maker'], institutionPolicyInput());
    $c = ['maker' => $finance['maker'], 'reviewer' => $finance['reviewer'], 'policy' => $policy];
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;
    InstitutionPolicyAnalyst::fake(array_fill(0, 3, institutionPolicyAnswer()));
    assessInstitutionPolicy($c);
    assessInstitutionPolicy($c, 'examples');
    $workspace->publish($finance['reviewer'], $policy, $policy->content_digest, null, 'Policy checked.');
    config(['eduflow.background_finance.enabled' => true, 'eduflow.institution_policy.advice_enabled' => true]);
    $snapshot = BudgetSnapshot::query()->firstOrFail();
    app(FinanceWorkflows::class)->budgetCaptured($snapshot);
    $run = FinanceWorkflowRun::query()->where('budget_snapshot_id', $snapshot->id)->sole();
    app(FinanceWorkflows::class)->process($run->id);
    Bus::fake();
    app(InstitutionPolicyAdvice::class)->forPlan($run->fresh());
    Bus::assertDispatchedTimes(AssessInstitutionPolicy::class, 2);
    $superseding = app(CreateFinancePolicyVersion::class)->handle($finance['maker'], 'supersede', Money::fromDecimal('2', CurrencyCode::USDC),
        new Money(0, CurrencyCode::USDC), new Money(0, CurrencyCode::USDC), new Money(1_000000, CurrencyCode::USDC));
    app(ActivateFinancePolicy::class)->handle($finance['reviewer'], $superseding, FinancePolicyActivation::current($policy->organization_id)?->id);
    app(InstitutionPolicyAdvice::class)->forPlan($run->fresh());
    Bus::assertDispatchedTimes(AssessInstitutionPolicy::class, 2);
    $queued = InstitutionPolicyAssessment::query()->where('state', 'queued')->firstOrFail();
    (new AssessInstitutionPolicy($queued->id))->handle(app(InstitutionPolicyInterpreter::class), app(InstallationInstitution::class), app(AiProviderResolver::class));
    expect($queued->fresh()->state)->toBe('needs_review')->and($queued->fresh()->result)->toBeNull();
});

test('playground shows only the signed in author assessments while automated advice stays separate', function (): void {
    $c = institutionPolicyContext();
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;
    InstitutionPolicyAnalyst::fake([institutionPolicyAnswer()]);
    $mine = $this->actingAs($c['maker'])->postJson(route('finance.policies.assess', $c['policy']),
        ['kind' => 'classification', 'request_key' => (string) Str::uuid(), 'expected_digest' => $c['policy']->content_digest, 'description' => 'My chemistry consumables'])
        ->assertSuccessful()->json('data.id');
    Bus::fake();
    $billAdvice = InstitutionPolicyAssessment::query()->create(['organization_id' => $c['policy']->organization_id,
        'institution_policy_version_id' => $c['policy']->id, 'requested_by' => null, 'request_key' => (string) Str::uuid(),
        'kind' => 'classification', 'input' => ['description' => 'Automated vendor bill'], 'input_digest' => PaymentIntent::digest(['description' => 'Automated vendor bill']),
        'policy_digest' => $c['policy']->content_digest]);
    $otherReviewer = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $theirs = $this->actingAs($otherReviewer)->postJson(route('finance.policies.assess', $c['policy']),
        ['kind' => 'classification', 'request_key' => (string) Str::uuid(), 'expected_digest' => $c['policy']->content_digest, 'description' => 'Someone else description'])
        ->assertSuccessful()->json('data.id');
    $this->actingAs($c['maker'])->get(route('finance.policies.index'))
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page->component('finance/policies/index')
            ->has('assessments', 1)->has('planAdvice', 1)
            ->where('assessments.0.id', $mine)->where('assessments.0.input.description', 'My chemistry consumables')
            ->where('planAdvice.0.id', $billAdvice->id)
            ->missing('assessments.1')->where('planAdvice.0.input.description', 'Automated vendor bill'));
    expect(InstitutionPolicyAssessment::query()->findOrFail($theirs)->requested_by)->toBe($otherReviewer->id);
});

test('AI output cannot smuggle spending permission or erase unknown classification', function (): void {
    $c = institutionPolicyContext();
    $payload = [...institutionPolicyAnswer(null), 'approved' => true, 'can_execute' => true,
        'recipient' => '0x'.str_repeat('a', 40), 'amount' => '90000000', 'needs_review' => false];
    $answer = app(InstitutionPolicyInterpreter::class)->sanitize($c['policy'], $payload, 'classification');
    expect($answer['can_execute'])->toBeFalse()->and($answer['needs_review'])->toBeTrue()->and($answer['category_id'])->toBeNull()
        ->and($answer)->not->toHaveKeys(['approved', 'recipient', 'amount']);
});
