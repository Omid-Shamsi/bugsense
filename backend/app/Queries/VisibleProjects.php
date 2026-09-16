<?php

namespace App\Queries;

use App\Models\Project;
use App\Models\User;
use App\Support\Authorization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Permission-filtered project discovery (FR-004). Always re-derives the
 * current grant set from the database; never trusts a client-selected
 * workspace as authority.
 */
class VisibleProjects
{
    /**
     * @return Builder<Project>
     */
    public function forUser(User $user): Builder
    {
        if (Authorization::isSystemAdmin($user)) {
            // System-wide Admins have administrative visibility across every
            // project, including inactive ones they may need to reactivate,
            // without requiring membership (FR-004).
            return Project::query();
        }

        return Project::query()
            ->where('is_active', true)
            ->whereHas('memberships', function (Builder $query) use ($user) {
                $query->where('user_id', $user->id)->where('is_active', true);
            });
    }
}
