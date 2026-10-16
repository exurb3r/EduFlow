<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\InstitutionPolicyAnalyst;
use App\Models\InstitutionPolicyVersion;
use App\Services\Advisory\JevPolicyClassifier;
use App\Services\Ai\AiProviderResolver;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Responses\StructuredAgentResponse;

final readonly class InstitutionPolicyInterpreter
{
    public function __construct(private AiSettings $settings, private AiProviderResolver $providers, private JevPolicyClassifier $jev) {}

    /** @return array{name:?string, model:?string} */
    public function classificationProvider(): array
    {
        if (config('eduflow.institution_policy.classification_engine') === 'jev') {
            $name = (string) config('ai.default_for_classification', 'typesafe');

            return ['name' => $name, 'model' => config('ai.providers.'.$name.'.models.classification.default', $name === 'typesafe' ? 'jev-latest' : null)];
        }
        $description = $this->providers->describe();

        return ['name' => $description['name'], 'model' => $description['model']];
    }

    /** @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    public function interpret(InstitutionPolicyVersion $policy, string $kind, array $evidence, int $remainingSeconds = 60): array
    {
        if (! $this->settings->mayCallProvider()) {
            throw ValidationException::withMessages(['ai' => 'AI advisory is disabled or provider disclosure has not been accepted. No policy data was sent.']);
        }
        if ($kind === 'classification' && config('eduflow.institution_policy.classification_engine') === 'jev') {
            $result = $this->sanitize($policy, $this->jev->classify($policy, $evidence, $remainingSeconds), $kind);

            return [...$result, 'jev_called' => true];
        }
        $provider = $this->providers->resolve();
        if ($provider === null) {
            throw ValidationException::withMessages(['ai' => 'Configure an approved text provider before interpreting policy.']);
        }
        $prompt = json_encode(['task' => $kind, 'policy_version_id' => $policy->id, 'policy_digest' => $policy->content_digest,
            'trusted_policy' => $policy->content, 'untrusted_transaction_evidence' => $evidence], JSON_THROW_ON_ERROR);
        $response = InstitutionPolicyAnalyst::make()->prompt($prompt, provider: $provider,
            timeout: min($remainingSeconds, 60, max(3, $this->settings->timeout_seconds)));
        $raw = $response instanceof StructuredAgentResponse ? $response->toArray() : [];
        if ($raw === []) {
            $raw = json_decode((string) $response, true);
        }
        if (! is_array($raw)) {
            throw ValidationException::withMessages(['ai' => 'Provider returned no usable policy interpretation.']);
        }

        return $this->sanitize($policy, $raw, $kind);
    }

    /** @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public function sanitize(InstitutionPolicyVersion $policy, array $raw, string $kind): array
    {
        $data = Validator::make($raw, [
            'category_id' => ['present', 'nullable', Rule::in(array_column($policy->content['categories'], 'id'))],
            'citations' => ['present', 'array', 'max:10'], 'citations.*' => ['array:clause_id,quote'],
            'citations.*.clause_id' => ['required', 'string'], 'citations.*.quote' => ['required', 'string', 'max:4000'],
            'explanation' => ['required', 'string', 'max:2000'], 'questions' => ['present', 'array', 'max:20'],
            'questions.*' => ['required', 'string', 'max:1000'], 'missing_evidence' => ['present', 'array', 'max:20'],
            'missing_evidence.*' => ['required', 'string', 'max:1000'], 'priority' => ['required', Rule::in(['high', 'normal', 'low'])],
            'needs_review' => ['required', 'boolean'],
            'confidence' => ['sometimes', 'nullable', 'numeric'],
            'probabilities' => ['sometimes', 'nullable', 'array'],
            'top_category_id' => ['sometimes', 'nullable', 'string'],
            'top_category_name' => ['sometimes', 'nullable', 'string'],
            'decision_process' => ['sometimes', 'nullable', 'array'],
        ])->validate();
        foreach ($data['citations'] as $citation) {
            $clause = collect($policy->content['clauses'])->firstWhere('id', $citation['clause_id']);
            if ($clause === null || ! str_contains($clause['text'], $citation['quote'])) {
                throw ValidationException::withMessages(['citations' => 'Provider citation did not match original policy text. Interpretation refused.']);
            }
        }
        if ($data['category_id'] !== null && $kind !== 'authoring') {
            $category = collect($policy->content['categories'])->firstWhere('id', $data['category_id']);
            if ($category === null || array_intersect($category['clause_ids'], array_column($data['citations'], 'clause_id')) === []) {
                throw ValidationException::withMessages(['citations' => 'Category advice must cite at least one of that category’s source clauses.']);
            }
        }
        if ($kind !== 'authoring' && $data['category_id'] !== null && $data['citations'] === []) {
            throw ValidationException::withMessages(['citations' => 'Classification needs a source-linked policy citation.']);
        }
        $data['needs_review'] = (bool) $data['needs_review'] || ($kind !== 'authoring' && $data['category_id'] === null)
            || $data['questions'] !== [] || $data['missing_evidence'] !== [];

        return $data + ['advisory_only' => true, 'can_execute' => false, 'confidence' => $data['confidence'] ?? null, 'policy_version_id' => $policy->id,
            'policy_digest' => $policy->content_digest, 'jev_called' => false];
    }
}
