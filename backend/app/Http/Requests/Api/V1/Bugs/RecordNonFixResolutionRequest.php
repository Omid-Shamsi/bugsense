<?php

namespace App\Http\Requests\Api\V1\Bugs;

use App\Models\Bug;
use App\Queries\VisibleBugs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecordNonFixResolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['reason', 'attempted_steps', 'environment', 'decision_rationale'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = trim($this->input($field));
            }
        }
        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outcome' => ['required', Rule::in(['duplicate', 'cannot_reproduce', 'wont_fix'])],
            'reason' => ['required', 'string', 'min:1'],
            'duplicate_bug_id' => ['nullable', 'string'],
            'attempted_steps' => ['nullable', 'string'],
            'environment' => ['nullable', 'string'],
            'decision_rationale' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $outcome = $this->input('outcome');

            match ($outcome) {
                'duplicate' => $this->validateDuplicateTarget($validator),
                'cannot_reproduce' => $this->requireNonblank($validator, ['attempted_steps', 'environment']),
                'wont_fix' => $this->requireNonblank($validator, ['decision_rationale']),
                default => null,
            };
        });
    }

    private function requireNonblank(Validator $validator, array $fields): void
    {
        foreach ($fields as $field) {
            $value = $this->input($field);
            if (! is_string($value) || trim($value) === '') {
                $validator->errors()->add($field, 'The '.$field.' field is required for this outcome.');
            }
        }
    }

    private function validateDuplicateTarget(Validator $validator): void
    {
        $raw = $this->input('duplicate_bug_id');

        if (! is_string($raw) || trim($raw) === '') {
            $validator->errors()->add('duplicate_bug_id', 'The duplicate_bug_id field is required for this outcome.');

            return;
        }

        $target = (new Bug())->resolveRouteBinding(trim($raw));

        // Non-disclosure: a target the actor cannot view is reported the
        // same as one that does not exist at all (FR-004/FR-005).
        if ($target !== null && ! (new VisibleBugs())->forUser($this->user())->whereKey($target->id)->exists()) {
            $target = null;
        }

        if ($target === null) {
            $validator->errors()->add('duplicate_bug_id', 'The original bug could not be found.');

            return;
        }

        $this->merge(['duplicate_target' => $target]);
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedEvidence(): array
    {
        return [
            'attempted_steps' => $this->input('attempted_steps'),
            'environment' => $this->input('environment'),
            'decision_rationale' => $this->input('decision_rationale'),
            'duplicate_target' => $this->input('duplicate_target'),
        ];
    }
}
