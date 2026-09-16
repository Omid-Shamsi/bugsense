<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Models\MembershipRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateMembershipRole
{
    use RecordsActivity;

    /**
     * No open Bug work can reference an assignee-eligible role yet (the Bug
     * domain does not exist in this phase), so there is no remediation to
     * enforce here. A later phase (T032) extends this once assignment exists.
     */
    public function handle(User $actor, MembershipRole $role): MembershipRole
    {
        return DB::transaction(function () use ($actor, $role) {
            $before = $role->only(['is_active']);

            $role->is_active = false;
            $role->deactivated_at = now();
            $role->deactivated_by_id = $actor->id;
            $role->save();

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'membership_role',
                subjectId: $role->id,
                eventType: 'membership_role.deactivated',
                before: $before,
                after: $role->only(['is_active']),
                projectId: $role->membership->project_id,
            );

            return $role;
        });
    }
}
