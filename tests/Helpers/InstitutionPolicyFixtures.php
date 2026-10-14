<?php

declare(strict_types=1);

use App\Ai\Agents\InstitutionPolicyAnalyst;
use App\Jobs\AssessInstitutionPolicy;
use App\Models\InstitutionPolicyAssessment;
use App\Models\PaymentIntent;
use App\Services\Ai\AiProviderResolver;
use App\Services\DefaultInstitutionPolicy;
use App\Services\InstallationInstitution;
use App\Services\InstitutionPolicyInterpreter;
use App\Services\InstitutionPolicyWorkspace;
use App\Settings\AiSettings;
use Illuminate\Support\Str;

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
