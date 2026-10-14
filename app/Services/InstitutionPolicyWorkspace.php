<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\ActivateFinancePolicy;
use App\Actions\CreateFinancePolicyVersion;
use App\DTOs\Money;
use App\Enums\CurrencyCode;
use App\Http\Requests\StoreInstitutionPolicyRequest;
use App\Models\FinancePolicyActivation;
use App\Models\InstitutionPolicyAssessment;
use App\Models\InstitutionPolicyPublication;
use App\Models\InstitutionPolicyVersion;
use App\Models\Organization;
use App\Models\PaymentIntent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class InstitutionPolicyWorkspace
{
    public function __construct(private InstallationInstitution $institutions, private CreateFinancePolicyVersion $createFinance,
        private ActivateFinancePolicy $activateFinance) {}

    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data): InstitutionPolicyVersion
    {
        Gate::forUser($actor)->authorize('create', InstitutionPolicyVersion::class);
        $data = Validator::make($data, StoreInstitutionPolicyRequest::contentRules())->validate();
        $clauseIds = array_column($data['clauses'], 'id');
        $categoryIds = [...array_column($data['categories'], 'id'), 'needs_review'];
        foreach ($data['categories'] as $category) {
            if (array_diff($category['clause_ids'], $clauseIds) !== []) {
                throw ValidationException::withMessages(['categories' => 'Every category must reference an existing policy clause.']);
            }
        }
        foreach ($data['examples'] as $example) {
            if (! in_array($example['category_id'], $categoryIds, true)) {
                throw ValidationException::withMessages(['examples' => 'Example must use an institution category or Needs review.']);
            }
        }
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($data, $actor, $institution): InstitutionPolicyVersion {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            if (($data['predecessor_id'] ?? null) !== null) {
                $previous = InstitutionPolicyVersion::query()->where('organization_id', $institution->id)->findOrFail($data['predecessor_id']);
                Gate::forUser($actor)->authorize('view', $previous);
            }
            $amounts = [];
            foreach ($data['controls'] as $key => $value) {
                try {
                    $amounts[$key] = Money::fromDecimal($value, CurrencyCode::USDC);
                } catch (Throwable) {
                    throw ValidationException::withMessages(['controls.'.$key => 'Enter an exact USDC decimal with at most six decimals.']);
                }
            }
            $finance = $this->createFinance->handle($actor, 'workspace-'.$data['request_key'], $amounts['minimum_reserve'],
                $amounts['max_auto_payment'], $amounts['max_daily_disbursement'], $amounts['max_fee']);
            $content = ['schema_version' => 1, 'institution_id' => $institution->id, 'created_by' => $actor->id,
                'title' => $data['title'], 'predecessor_id' => $data['predecessor_id'] ?? null,
                'expected_current_digest' => $data['expected_current_digest'] ?? null,
                'clauses' => $data['clauses'], 'categories' => $data['categories'], 'examples' => $data['examples'],
                'controls' => $data['controls'], 'priorities' => $data['priorities'] ?? '', 'clarifications' => $data['clarifications'],
                'finance_policy_digest' => $finance->content_digest, 'can_execute' => false];
            $existing = InstitutionPolicyVersion::query()->where('request_key', $data['request_key'])->first();
            $content['expected_finance_activation_id'] = $existing !== null
                ? $existing->content['expected_finance_activation_id'] : FinancePolicyActivation::current($institution->id)?->id;
            $digest = PaymentIntent::digest($content);
            if ($existing !== null) {
                if ($existing->organization_id !== $institution->id || $existing->content_digest !== $digest) {
                    throw ValidationException::withMessages(['request_key' => 'Save identity already binds different policy. Save a new revision.']);
                }

                return $existing;
            }
            $version = InstitutionPolicyVersion::query()->create(['organization_id' => $institution->id, 'created_by' => $actor->id,
                'finance_policy_version_id' => $finance->id, 'predecessor_id' => $data['predecessor_id'] ?? null,
                'request_key' => $data['request_key'], 'title' => $data['title'], 'content' => $content, 'content_digest' => $digest]);
            activity('finance')->causedBy($actor)->performedOn($version)->event('institution_policy_saved')
                ->withProperties(['content_digest' => $digest, 'can_execute' => false])->log('Institution-authored policy revision saved for testing and independent review');

            return $version;
        }, 3);
    }

    public function current(): ?InstitutionPolicyPublication
    {
        $publication = InstitutionPolicyPublication::query()->where('organization_id', $this->institutions->require()->id)->latest('id')->first();
        if ($publication !== null && ! $publication->hasValidHistory()) {
            throw ValidationException::withMessages(['policy' => 'Published policy integrity failed. Independent investigation required.']);
        }

        return $publication;
    }

    public function publish(User $reviewer, InstitutionPolicyVersion $version, string $digest, ?string $expectedCurrent, string $reason): InstitutionPolicyPublication
    {
        Gate::forUser($reviewer)->authorize('publish', $version);
        $institution = $this->institutions->require();

        return DB::transaction(function () use ($reviewer, $version, $digest, $expectedCurrent, $reason, $institution): InstitutionPolicyPublication {
            Organization::query()->whereKey($institution->id)->lockForUpdate()->firstOrFail();
            $version = InstitutionPolicyVersion::query()->where('organization_id', $institution->id)->findOrFail($version->id);
            Gate::forUser($reviewer)->authorize('publish', $version);
            $current = $this->current();
            if (! $version->hasValidContent() || $version->content_digest !== $digest || trim($reason) === '' || mb_strlen($reason) > 1000
                || ($version->content['clarifications'] ?? []) !== []) {
                throw ValidationException::withMessages(['publication' => 'Exact tested policy and resolved clarifications required.']);
            }
            if ($current?->institution_policy_version_id === $version->id) {
                if ($current->approved_by !== $reviewer->id || $current->reason !== $reason
                    || ($version->content['expected_current_digest'] ?? null) !== $expectedCurrent) {
                    throw ValidationException::withMessages(['publication' => 'Publication replay differs from original independent review.']);
                }

                return $current;
            }
            if ($current?->publication_digest !== $expectedCurrent || ($version->content['expected_current_digest'] ?? null) !== $expectedCurrent) {
                throw ValidationException::withMessages(['publication' => 'Active policy changed. Create a revision against current policy before publishing.']);
            }
            if (InstitutionPolicyPublication::query()->where('institution_policy_version_id', $version->id)->exists()) {
                throw ValidationException::withMessages(['publication' => 'Superseded revisions cannot be reactivated. Save a reviewed successor revision.']);
            }
            $test = InstitutionPolicyAssessment::query()->where('institution_policy_version_id', $version->id)->where('kind', 'examples')
                ->latest('id')->first();
            if ($test === null || $test->policy_digest !== $version->content_digest || ! $test->hasValidResult() || ($test->result['all_passed'] ?? false) !== true) {
                throw ValidationException::withMessages(['publication' => 'Run saved policy examples and resolve mismatches before independent publication.']);
            }
            $rows = $test->result['examples'] ?? [];
            if (count($rows) !== count($version->content['examples']) || $rows === []) {
                throw ValidationException::withMessages(['publication' => 'Example results must cover every saved expected case.']);
            }
            foreach ($version->content['examples'] as $index => $example) {
                $row = $rows[$index] ?? null;
                if (! is_array($row) || ($row['index'] ?? null) !== $index || ($row['expected'] ?? null) !== $example['category_id']
                    || ($row['actual'] ?? null) !== $example['category_id'] || ($row['passed'] ?? null) !== true) {
                    throw ValidationException::withMessages(['publication' => 'Saved example coverage or expected category changed.']);
                }
            }
            $analysis = InstitutionPolicyAssessment::query()->where('institution_policy_version_id', $version->id)->where('kind', 'authoring')
                ->latest('id')->first();
            if ($analysis === null || $analysis->policy_digest !== $version->content_digest || ! $analysis->hasValidResult()
                || ($analysis->result['questions'] ?? []) !== [] || ($analysis->result['missing_evidence'] ?? []) !== []
                || ($analysis->result['needs_review'] ?? true) !== false) {
                throw ValidationException::withMessages(['publication' => 'Complete policy interpretation and resolve its clarification questions before publication.']);
            }
            $activation = $this->activateFinance->handle($reviewer, $version->financePolicy, $version->content['expected_finance_activation_id'] ?? null);
            $publication = new InstitutionPolicyPublication(['organization_id' => $institution->id, 'institution_policy_version_id' => $version->id,
                'approved_by' => $reviewer->id, 'finance_policy_activation_id' => $activation->id, 'previous_publication_id' => $current?->id,
                'policy_digest' => $version->content_digest, 'reason' => $reason]);
            $publication->publication_digest = PaymentIntent::digest($publication->content());
            $publication->save();
            activity('finance')->causedBy($reviewer)->performedOn($publication)->event('institution_policy_published')
                ->withProperties(['policy_digest' => $digest, 'can_execute' => false])->log('Institution policy independently published; no payment approved');

            return $publication;
        }, 3);
    }
}
