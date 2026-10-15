<?php

declare(strict_types=1);

use App\Ai\Agents\InstitutionPolicyAnalyst;
use App\Jobs\AssessInstitutionPolicy;
use App\Models\InstitutionPolicyAssessment;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Ai\AiProviderResolver;
use App\Services\DefaultInstitutionPolicy;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyInterpreter;
use App\Services\InstitutionPolicyWorkspace;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/** @return array<string, mixed> */
function institutionPolicyInput(): array
{
    return ['request_key' => (string) Str::uuid(), 'title' => 'Campus finance policy', 'predecessor_id' => null, 'expected_current_digest' => null,
        'clauses' => [['id' => 'materials', 'title' => 'Teaching purchases', 'text' => 'Laboratory consumables belong to Teaching Materials. Equipment with multi-year use belongs to Capital Equipment.']],
        'categories' => [
            ['id' => 'teaching-materials', 'name' => 'Teaching Materials', 'description' => 'Consumables used in teaching.', 'inclusions' => 'Chemicals, disposable pipettes', 'exclusions' => 'Long-lived equipment', 'clause_ids' => ['materials']],
            ['id' => 'capital-equipment', 'name' => 'Capital Equipment', 'description' => 'Equipment used over multiple years.', 'inclusions' => 'Microscopes', 'exclusions' => 'Consumables', 'clause_ids' => ['materials']],
        ], 'examples' => [['description' => 'Disposable chemistry pipettes for classes', 'category_id' => 'teaching-materials']],
        'controls' => ['minimum_reserve' => '10.000001', 'max_auto_payment' => '0', 'max_daily_disbursement' => '500', 'max_fee' => '0.1'],
        'priorities' => 'Prioritize essential teaching purchases due this week.', 'clarifications' => []];
}

/** @return array<string, mixed> */
function institutionPolicyAnswer(?string $category = 'teaching-materials'): array
{
    return ['category_id' => $category, 'citations' => [['clause_id' => 'materials', 'quote' => 'Laboratory consumables belong to Teaching Materials.']],
        'explanation' => 'Consumable teaching supplies match the institution definition.', 'questions' => [], 'missing_evidence' => [], 'priority' => 'high', 'needs_review' => false];
}

/** @return array<string, mixed> */
function institutionPolicyContext(): array
{
    config(['queue.default' => 'database', 'eduflow.institution_id' => null, 'queue.connections.database.retry_after' => 360]);
    Organization::factory()->create();
    $maker = User::factory()->create()->assignRole(Role::findOrCreate('finance_officer', 'web'));
    $reviewer = User::factory()->create()->assignRole(Role::findOrCreate('admin', 'web'));
    $policy = app(InstitutionPolicyWorkspace::class)->save($maker, institutionPolicyInput());

    return ['maker' => $maker, 'reviewer' => $reviewer, 'policy' => $policy];
}

/** @param array<string, mixed> $c */
function assessInstitutionPolicy(array $c, string $kind = 'authoring'): InstitutionPolicyAssessment
{
    Bus::fake();
    $response = test()->actingAs($c['maker'])->postJson(route('finance.policies.assess', $c['policy']), [
        'kind' => $kind, 'request_key' => (string) Str::uuid(), 'expected_digest' => $c['policy']->content_digest, 'description' => 'Chemistry supplies',
    ])->assertSuccessful();
    $id = $response->json('data.id');
    (new AssessInstitutionPolicy($id))->handle(app(InstitutionPolicyInterpreter::class), app(InstallationInstitution::class), app(AiProviderResolver::class));

    return InstitutionPolicyAssessment::query()->findOrFail($id);
}

/**
 * Publish exact editable defaults in isolated tests using policy-citing agent fakes.
 *
 * @param  array<string, mixed>  $context
 * @return array<string, mixed>
 */
function pilotPublishedDefaultPolicy(array $context, ?array $template = null): array
{
    $template ??= DefaultInstitutionPolicy::template();
    $template['request_key'] = (string) Str::uuid();
    $workspace = app(InstitutionPolicyWorkspace::class);
    $policy = $workspace->save($context['maker'], $template);
    app(AiSettings::class)->advisory_enabled = true;
    app(AiSettings::class)->disclosure_accepted = true;
    $clauses = array_column($policy->content['clauses'], 'text', 'id');
    $clausesByCategory = [];
    foreach ($policy->content['categories'] as $category) {
        $clausesByCategory[$category['id']] = $category['clause_ids'][0];
    }
    $answer = static fn (string $category): array => [
        'category_id' => $category,
        'citations' => [['clause_id' => $clausesByCategory[$category], 'quote' => $clauses[$clausesByCategory[$category]]]],
        'explanation' => 'Matches the institution definition for '.$category.'.',
        'questions' => [], 'missing_evidence' => [], 'priority' => 'high', 'needs_review' => false,
    ];
    $keywordCategory = ['council' => 'student-council', 'club' => 'student-clubs', 'facilit' => 'department-facilities',
        'robotics' => 'student-clubs', 'bench' => 'department-facilities'];
    InstitutionPolicyAnalyst::fake(static function (string $prompt) use ($answer, $keywordCategory): array {
        $decoded = json_decode($prompt, true);
        $evidence = is_array($decoded) ? strtolower((string) ($decoded['untrusted_transaction_evidence']['description'] ?? '')) : strtolower($prompt);
        foreach ($keywordCategory as $needle => $category) {
            if (str_contains($evidence, $needle)) {
                return $answer($category);
            }
        }

        return $answer('supplier-invoices');
    });
    foreach (['authoring', 'examples'] as $kind) {
        $assessment = InstitutionPolicyAssessment::query()->create(['organization_id' => $policy->organization_id,
            'institution_policy_version_id' => $policy->id, 'requested_by' => $context['maker']->id, 'request_key' => (string) Str::uuid(),
            'kind' => $kind, 'input' => [], 'input_digest' => PaymentIntent::digest([]), 'policy_digest' => $policy->content_digest]);
        (new AssessInstitutionPolicy($assessment->id))->handle(app(InstitutionPolicyInterpreter::class),
            app(InstallationInstitution::class), app(AiProviderResolver::class));
    }
    $publication = $workspace->publish($context['reviewer'], $policy, $policy->content_digest, null, 'Default institution policy reviewed and published.');

    return ['policy' => $policy, 'publication' => $publication];
}
