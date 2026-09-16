<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TrackingValue */
class TrackingValueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'kind' => $this->kind->value,
            'code' => $this->code,
            'name' => $this->name,
            'rank' => $this->rank,
            'active' => $this->is_active,
        ];
    }
}
