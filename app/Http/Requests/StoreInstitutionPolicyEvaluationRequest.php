<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\InstitutionPolicyEvaluation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInstitutionPolicyEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', InstitutionPolicyEvaluation::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_type' => ['required', Rule::in(['collection', 'transaction', 'budget_snapshot'])],
            'source_id' => ['required', 'integer', 'min:1'], 'source_digest' => ['required', 'string', 'size:64'],
            'expected_publication_digest' => ['required', 'string', 'size:64'], 'request_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'], 'amount_base_units' => ['prohibited'],
            'recipient' => ['prohibited'], 'can_execute' => ['prohibited'], 'result' => ['prohibited'],
        ];
    }
}
