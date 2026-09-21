<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;

/**
 * Central, reusable Admin-scope checks shared by Policies and Actions.
 * Workspace/UI context never factors in here: every check re-derives
 * current grants from the database.
 */
class Authorization
{
    public static function isSystemAdmin(User $user): bool
    {
        return $user->is_active && $user->is_system_admin;
    }

    public static function isProjectAdmin(User $user, Project $project): bool
    {
        return static::hasActiveRole($user, $project, Role::Admin);
    }

    public static function isAdminWithinScope(User $user, Project $project): bool
    {
        return static::isSystemAdmin($user) || static::isProjectAdmin($user, $project);
    }

    /**
     * True when the user has a current, active membership in the project
     * carrying the given active role. Always re-derived from the database.
     */
    public static function hasActiveRole(User $user, Project $project, Role $role): bool
    {
        if (! $user->is_active || ! $project->is_active) {
            return false;
        }

        $membership = $project->memberships()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        return $membership !== null && $membership->hasActiveRole($role);
    }
}
