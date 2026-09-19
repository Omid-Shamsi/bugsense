<?php

namespace App\Http\Requests\Api\V1\Bugs;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetSeverityRequest extends FormRequest
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
        /** @var \App\Models\Bug $bug */
        $bug = $this->route('bug');

        return [
            'severity_id' => [
                'required', 'uuid',
                Rule::exists('tracking_values', 'id')
                    ->where('project_id', $bug->project_id)->where('kind', 'severity')->where('is_active', true),
            ],
        ];
    }
}
