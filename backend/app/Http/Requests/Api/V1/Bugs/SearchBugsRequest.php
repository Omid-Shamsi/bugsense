<?php

namespace App\Http\Requests\Api\V1\Bugs;

use App\Http\Requests\Api\V1\Bugs\Concerns\ValidatesBugFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchBugsRequest extends FormRequest
{
    use ValidatesBugFilters;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->bugFilterRules() + [
            'sort' => ['nullable', Rule::in(['created_at', 'updated_at', 'priority', 'severity', 'public_id'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
