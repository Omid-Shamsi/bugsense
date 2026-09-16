<?php

namespace App\Http\Requests\Api\V1\Administration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('key')) {
            $this->merge(['key' => strtoupper(trim((string) $this->input('key')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'key' => ['sometimes', 'string', 'regex:/^[A-Z0-9-]{2,20}$/', Rule::unique('projects', 'key')->ignore($project)],
            'name' => ['sometimes', 'string', 'min:1', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
