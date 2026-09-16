<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\TrackingValue;
use App\Models\User;
use App\Support\Authorization;

class TrackingValuePolicy
{
    public function viewAny(User $actor, Project $project): bool
    {
        if (Authorization::isSystemAdmin($actor)) {
            return true;
        }

        return $project->is_active && $project->memberships()
            ->where('user_id', $actor->id)
            ->where('is_active', true)
            ->exists();
    }

    public function create(User $actor, Project $project): bool
    {
        return Authorization::isAdminWithinScope($actor, $project);
    }

    public function update(User $actor, TrackingValue $trackingValue): bool
    {
        return Authorization::isAdminWithinScope($actor, $trackingValue->project);
    }
}
