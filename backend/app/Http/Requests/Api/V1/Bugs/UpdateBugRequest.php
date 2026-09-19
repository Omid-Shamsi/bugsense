<?php

namespace App\Http\Requests\Api\V1\Bugs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBugRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('title') && is_string($this->input('title'))) {
            $merge['title'] = trim($this->input('title'));
        }
        if ($this->has('description') && is_string($this->input('description'))) {
            $merge['description'] = trim($this->input('description'));
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\Bug|null $bug */
        $bug = $this->route('bug');
        $projectId = $bug?->project_id;

        return [
            'title' => ['sometimes', 'string', 'min:1', 'max:200'],
            'description' => ['sometimes', 'string', 'min:1'],
            'steps_to_reproduce' => ['sometimes', 'nullable', 'string'],
            'expected_result' => ['sometimes', 'nullable', 'string'],
            'actual_result' => ['sometimes', 'nullable', 'string'],
            'environment' => ['sometimes', 'nullable', 'string'],
            'platform' => ['sometimes', 'nullable', 'string'],
            'application_version' => ['sometimes', 'nullable', 'string'],
            'category_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('tracking_values', 'id')
                    ->where('project_id', $projectId)->where('kind', 'category')->where('is_active', true),
            ],
            'priority_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('tracking_values', 'id')
                    ->where('project_id', $projectId)->where('kind', 'priority')->where('is_active', true),
            ],
            'severity_id' => [
                'sometimes', 'nullable', 'uuid',
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
