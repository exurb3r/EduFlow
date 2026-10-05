<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\AssistanceRequest;
use Illuminate\Foundation\Http\FormRequest;

class StoreAssistanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AssistanceRequest::class) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'submission_key' => ['required', 'uuid'],
            'type' => ['required', 'in:emergency'],
            'requested_amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,6})?\z/', 'numeric', 'gt:0', 'max:1000000'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'student_id' => ['prohibited'],
            'academic_term_id' => ['prohibited'],
            'status' => ['prohibited'],
            'approved_amount' => ['prohibited'],
            'ai_decision' => ['prohibited'],
        ];
    }
}
