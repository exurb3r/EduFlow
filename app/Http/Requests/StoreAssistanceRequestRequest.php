<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AssistanceCategory;
use App\Enums\AssistancePriority;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreAssistanceRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', new Enum(AssistanceCategory::class)],
            'priority' => ['required', new Enum(AssistancePriority::class)],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'min:5', 'max:5000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return [
            'category.required' => 'Please select a topic category.',
            'priority.required' => 'Please specify the urgency level.',
            'subject.required' => 'Please provide a brief subject for your inquiry.',
            'description.required' => 'Please describe what you need assistance with.',
            'description.min' => 'Please provide at least 5 characters describing your problem.',
        ];
    }
}
