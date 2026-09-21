<?php

namespace App\Http\Requests\Api\V1\Bugs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateBugRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => is_string($this->input('title')) ? trim($this->input('title')) : $this->input('title'),
            'description' => is_string($this->input('description')) ? trim($this->input('description')) : $this->input('description'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $projectId = $this->input('project_id');

        return [
            'project_id' => ['required', 'uuid', 'exists:projects,id'],
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'description' => ['required', 'string', 'min:1'],
            'steps_to_reproduce' => ['nullable', 'string'],
            'expected_result' => ['nullable', 'string'],
            'actual_result' => ['nullable', 'string'],
            'environment' => ['nullable', 'string'],
            'platform' => ['nullable', 'string'],
            'application_version' => ['nullable', 'string'],
            'category_id' => [
                'nullable', 'uuid',
                Rule::exists('tracking_values', 'id')
                    ->where('project_id', $projectId)->where('kind', 'category')->where('is_active', true),
            ],
            'priority_id' => [
                'nullable', 'uuid',
                Rule::exists('tracking_values', 'id')
                    ->where('project_id', $projectId)->where('kind', 'priority')->where('is_active', true),
            ],
            'severity_id' => [
                'nullable', 'uuid',
                Rule::exists('tracking_values', 'id')
                    ->where('project_id', $projectId)->where('kind', 'severity')->where('is_active', true),
            ],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => [
                'uuid', 'distinct',
                Rule::exists('tracking_values', 'id')
                    ->where('project_id', $projectId)->where('kind', 'tag')->where('is_active', true),
            ],
        ];
    }
}
