<?php

namespace App\Http\Requests\Api\V1\Administration;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTrackingValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $project = $this->route('project');

        return [
            'kind' => ['required', Rule::in(['category', 'priority', 'severity', 'tag', 'resolution_label'])],
            'code' => [
                'required',
                'string',
                'max:60',
                Rule::unique('tracking_values', 'code')
                    ->where('project_id', $project?->id)
                    ->where('kind', $this->input('kind')),
            ],
            'name' => ['required', 'string', 'min:1', 'max:150'],
            'rank' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
