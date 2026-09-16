<?php

namespace App\Http\Requests\Api\V1\Administration;

use Illuminate\Foundation\Http\FormRequest;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users,email'],
            'display_name' => ['required', 'string', 'min:1', 'max:150'],
            'password' => ['required', 'string', 'min:12'],
            'is_system_admin' => ['sometimes', 'boolean'],
        ];
    }
}
