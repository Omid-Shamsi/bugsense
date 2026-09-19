<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ActivityEvent */
class ActivityEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sequence' => $this->sequence,
            'type' => $this->event_type,
            // actor_display is the immutable snapshot recorded at the time of
            // the event (data-model.md); the live actor relation only
            // supplies current account state (e.g. active), never the name.
            'actor' => $this->actor_id !== null ? [
                'id' => $this->actor_id,
                'display_name' => $this->actor_display,
                'active' => $this->actor?->is_active ?? true,
            ] : null,
            'occurred_at' => $this->occurred_at,
            'before' => $this->before_data,
            'after' => $this->after_data,
            'reason_or_result' => $this->reason_or_result,
        ];
    }
}
