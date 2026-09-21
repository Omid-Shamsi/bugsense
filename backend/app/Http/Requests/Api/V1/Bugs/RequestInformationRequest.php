<?php

namespace App\Http\Requests\Api\V1\Bugs;

use Illuminate\Foundation\Http\FormRequest;

class RequestInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('request'))) {
            $this->merge(['request' => trim($this->input('request'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'request' => ['required', 'string', 'min:1'],
        ];
    }
}
