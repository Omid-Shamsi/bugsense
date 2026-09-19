<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin array<string, mixed> */
class DashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'context' => $this->resource['context'],
            'counts' => $this->resource['counts'],
            'breakdowns' => $this->resource['breakdowns'],
            'average_resolution_time' => $this->resource['average_resolution_time'],
        ];
    }
}
