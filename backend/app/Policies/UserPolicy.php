<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization;

/**
 * System-wide user management is exclusive to system-wide Admins (FR-006).
 * No project-scoped Admin grant ever satisfies these abilities.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return Authorization::isSystemAdmin($actor);
    }

    public function view(User $actor, User $target): bool
    {
        return Authorization::isSystemAdmin($actor);
    }

    public function create(User $actor): bool
    {
        return Authorization::isSystemAdmin($actor);
    }

    public function update(User $actor, User $target): bool
    {
        return Authorization::isSystemAdmin($actor);
    }
}
