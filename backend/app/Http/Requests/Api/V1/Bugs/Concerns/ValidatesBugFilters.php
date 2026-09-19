<?php

namespace App\Http\Requests\Api\V1\Bugs\Concerns;

use App\Enums\BugStatus;
use Illuminate\Validation\Rule;

/**
 * The FR-036 filter dimensions, shared verbatim by the Bug list and
 * Dashboard requests so the two endpoints can never validate (and
 * therefore query) a different filter shape (FR-039).
 */
trait ValidatesBugFilters
{
    /**
     * @return array<string, mixed>
     */
    protected function bugFilterRules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'project' => ['nullable', 'array'],
            'project.*' => ['string'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(array_map(fn (BugStatus $status) => $status->value, BugStatus::cases()))],
            'severity' => ['nullable', 'array'],
            'severity.*' => ['uuid'],
            'priority' => ['nullable', 'array'],
            'priority.*' => ['uuid'],
            'category' => ['nullable', 'array'],
            'category.*' => ['uuid'],
            'reporter' => ['nullable', 'array'],
            'reporter.*' => ['uuid'],
            'assignee' => ['nullable', 'array'],
            'assignee.*' => ['string', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== 'unassigned' && ! \Illuminate\Support\Str::isUuid((string) $value)) {
                    $fail('Each assignee must be a user UUID or the literal "unassigned".');
                }
            }],
            'tag' => ['nullable', 'array'],
            'tag.*' => ['uuid'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'updated_from' => ['nullable', 'date'],
            'updated_to' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function bugFilters(): array
    {
        return [
            'q' => $this->input('q'),
            'project' => $this->input('project', []),
            'status' => $this->input('status', []),
            'severity' => $this->input('severity', []),
            'priority' => $this->input('priority', []),
            'category' => $this->input('category', []),
            'reporter' => $this->input('reporter', []),
            'assignee' => $this->input('assignee', []),
            'tag' => $this->input('tag', []),
            'created_from' => $this->input('created_from'),
            'created_to' => $this->input('created_to'),
            'updated_from' => $this->input('updated_from'),
            'updated_to' => $this->input('updated_to'),
        ];
    }
}
