<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never exposes `storage_key`, an absolute path, or the private disk root —
 * only presentation-safe metadata plus the authorized API download route.
 *
 * @mixin \App\Models\Attachment
 */
class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bug_id' => $this->bug_id,
            'uploaded_by' => UserSummaryResource::make($this->uploadedBy),
            'original_name' => $this->original_name,
            'declared_content_type' => $this->declared_content_type,
            'detected_content_type' => $this->detected_content_type,
            'byte_size' => $this->byte_size,
            'state' => $this->state,
            'created_at' => $this->created_at,
            'removed_at' => $this->removed_at,
            // A plain API path, not a named route — this project does not
            // use named routes elsewhere; kept consistent with that.
            'download_url' => $this->state === 'ready' ? url("/api/v1/attachments/{$this->id}") : null,
        ];
    }
}
