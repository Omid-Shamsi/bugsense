<?php

namespace App\Http\Requests\Api\V1\Bugs;

use App\Http\Requests\Api\V1\Bugs\Concerns\ValidatesBugFilters;
use Illuminate\Foundation\Http\FormRequest;

class DashboardFiltersRequest extends FormRequest
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
        return $this->bugFilterRules();
    }
}
