<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\InstitutionPolicyVersion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreInstitutionPolicyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', InstitutionPolicyVersion::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::contentRules();
    }

    /** @return array<string, mixed> */
    public static function contentRules(): array
    {
        return [
            'request_key' => ['required', 'uuid'], 'expected_current_digest' => ['nullable', 'string', 'size:64'],
            'predecessor_id' => ['nullable', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:160'],
            'clauses' => ['required', 'array', 'list', 'min:1', 'max:40'],
            'clauses.*' => ['array:id,title,text'], 'clauses.*.id' => ['required', 'string', 'regex:/^[a-zA-Z0-9._-]{1,64}$/D', 'distinct'],
            'clauses.*.title' => ['required', 'string', 'max:160'], 'clauses.*.text' => ['required', 'string', 'max:4000'],
            'categories' => ['required', 'array', 'list', 'min:1', 'max:30'], 'categories.*' => ['array:id,name,description,inclusions,exclusions,clause_ids'],
            'categories.*.id' => ['required', 'string', 'regex:/^[a-zA-Z0-9._-]{1,64}$/D', 'distinct', 'not_in:needs_review'],
            'categories.*.name' => ['required', 'string', 'max:120'], 'categories.*.description' => ['required', 'string', 'max:1000'],
            'categories.*.inclusions' => ['nullable', 'string', 'max:1000'], 'categories.*.exclusions' => ['nullable', 'string', 'max:1000'],
            'categories.*.clause_ids' => ['required', 'array', 'list', 'min:1'], 'categories.*.clause_ids.*' => ['required', 'string', 'max:64'],
            'examples' => ['present', 'array', 'list', 'max:30'], 'examples.*' => ['array:description,category_id'],
            'examples.*.description' => ['required', 'string', 'max:2000'], 'examples.*.category_id' => ['required', 'string', 'max:64'],
            'controls' => ['required', 'array:minimum_reserve,max_auto_payment,max_daily_disbursement,max_fee'],
            'controls.minimum_reserve' => ['required', 'string', 'max:30'], 'controls.max_auto_payment' => ['required', 'string', 'max:30'],
            'controls.max_daily_disbursement' => ['required', 'string', 'max:30'], 'controls.max_fee' => ['required', 'string', 'max:30'],
            'priorities' => ['nullable', 'string', 'max:4000'], 'clarifications' => ['present', 'array', 'list', 'max:20'],
            'clarifications.*' => ['required', 'string', 'max:1000'],
        ];
    }
}
