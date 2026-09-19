<?php

namespace App\Http\Requests\Api\V1\Bugs;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape validation only — "present and is a file". Size/type rejection is a
 * domain rule with its own HTTP semantics (413/415), decided by
 * UploadAttachment, not by generic 422 Form Request rules.
 */
class UploadAttachmentRequest extends FormRequest
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
            'file' => ['required', 'file'],
        ];
    }
}
