<?php

namespace App\Http\Requests\Api\V1\Bugs;

use App\Models\Bug;
use App\Queries\VisibleBugs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CreateBugRelationshipRequest extends FormRequest
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
        return [
            'type' => ['required', Rule::in(['duplicate_of', 'related_to', 'blocks'])],
            'target_bug_id' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $raw = $this->input('target_bug_id');

            if (! is_string($raw) || trim($raw) === '') {
                return;
            }

            $target = (new Bug())->resolveRouteBinding(trim($raw));

            // Non-disclosure: a target the actor cannot view is reported the
            // same as one that does not exist at all (FR-004/FR-005/FR-033).
            if ($target !== null && ! (new VisibleBugs())->forUser($this->user())->whereKey($target->id)->exists()) {
                $target = null;
            }

            if ($target === null) {
                $validator->errors()->add('target_bug_id', 'The target bug could not be found.');

                return;
            }

            $this->merge(['resolved_target' => $target]);
        });
    }

    public function resolvedTarget(): Bug
    {
        return $this->input('resolved_target');
    }
}
