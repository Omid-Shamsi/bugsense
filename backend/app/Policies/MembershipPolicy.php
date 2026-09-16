<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use App\Support\Authorization;

class MembershipPolicy
{
    public function viewAny(User $actor, Project $project): bool
    {
        return Authorization::isAdminWithinScope($actor, $project);
    }

    public function create(User $actor, Project $project): bool
    {
        return Authorization::isAdminWithinScope($actor, $project);
    }

    public function update(User $actor, Membership $membership): bool
    {
        return Authorization::isAdminWithinScope($actor, $membership->project);
    }

    /**
     * Only a system-wide Admin may grant or revoke the Admin role itself
     * (FR-002, FR-006): a project-scoped Admin may manage ordinary roles
     * only. Callers check this in addition to update() whenever the
     * requested role set changes 'admin' membership.
     */
    public function manageAdminRole(User $actor): bool
    {
        return Authorization::isSystemAdmin($actor);
    }
}
