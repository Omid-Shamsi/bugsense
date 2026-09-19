<?php

namespace App\Queries;

use App\Enums\Role;
use App\Models\Bug;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bugs currently assigned to the authenticated Developer, via their own
 * current active memberships/roles only (FR-018). Never trusts a
 * client-selected workspace; always re-derives from current DB grants.
 */
class AssignedDeveloperBugs
{
    /**
     * @return Builder<Bug>
     */
    public function forUser(User $user): Builder
    {
        $eligibleMembershipIds = Membership::where('user_id', $user->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('role', Role::Developer->value)->where('is_active', true))
            ->select('id');

        return Bug::query()->whereIn('assignee_membership_id', $eligibleMembershipIds);
    }
}
