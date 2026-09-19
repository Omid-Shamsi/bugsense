<?php

namespace App\Http\Resources\Api\V1;

use App\Models\BugRelationship;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BugRelationship
 *
 * Presents the relationship from a specific Bug's point of view (the
 * $viewpointBugId), so the frontend never has to reverse-engineer
 * directional meaning itself (T047).
 */
class BugRelationshipResource extends JsonResource
{
    public function __construct(BugRelationship $resource, private readonly ?string $viewpointBugId = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isViewedFromTarget = $this->viewpointBugId !== null && $this->viewpointBugId === $this->target_bug_id;

        return [
            'id' => $this->id,
            'type' => $this->relationship_type->value,
            'active' => $this->is_active,
            'source_bug_id' => $this->sourceBug->public_id,
            'target_bug_id' => $this->targetBug->public_id,
            'label' => $isViewedFromTarget ? $this->reverseLabel() : $this->forwardLabel(),
            'created_by' => UserSummaryResource::make($this->createdBy),
            'created_at' => $this->created_at,
            'deactivated_at' => $this->deactivated_at,
        ];
    }
}
