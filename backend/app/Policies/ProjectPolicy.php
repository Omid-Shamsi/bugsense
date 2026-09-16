<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Queries\VisibleProjects;
use App\Support\Authorization;

class ProjectPolicy
{
    /**
     * Project membership grants viewing access regardless of role (FR-004).
     * System-wide Admins may view any project without membership. Shares
     * its rule with VisibleProjects so the single-object check and the
     * discovery list can never drift apart.
     */
    public function view(User $actor, Project $project): bool
    {
        return (new VisibleProjects())->forUser($actor)->whereKey($project->id)->exists();
    }

    /**
     * Only a system-wide Admin may create a project: a project has no
     * members yet, so no project-scoped Admin authority can exist for it.
     */
    public function create(User $actor): bool
    {
        return Authorization::isSystemAdmin($actor);
    }

    public function update(User $actor, Project $project): bool
    {
        return Authorization::isAdminWithinScope($actor, $project);
    }
}
