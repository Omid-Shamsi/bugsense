<?php

namespace App\Http\Requests\Api\V1\Bugs;

use Illuminate\Foundation\Http\FormRequest;

class ReopenClosedBugRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1'],
        ];
    }
}
