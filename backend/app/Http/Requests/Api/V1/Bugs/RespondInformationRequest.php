<?php

namespace App\Http\Requests\Api\V1\Bugs;

use Illuminate\Foundation\Http\FormRequest;

class RespondInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('response'))) {
            $this->merge(['response' => trim($this->input('response'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'response' => ['required', 'string', 'min:1'],
        ];
    }
}
