<?php

namespace App\Http\Requests\Api\V1\Bugs;

use Illuminate\Foundation\Http\FormRequest;

class ResolveFixedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['explanation', 'qa_instructions'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = trim($this->input($field));
            }
        }
        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'explanation' => ['required', 'string', 'min:1'],
            'qa_instructions' => ['required', 'string', 'min:1'],
        ];
    }
}
