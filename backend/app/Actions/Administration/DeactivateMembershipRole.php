<?php

namespace App\Actions\Administration;

use App\Actions\Administration\Concerns\RemediatesBugAssignments;
use App\Actions\Concerns\RecordsActivity;
use App\Enums\Role;
use App\Models\Bug;
use App\Models\MembershipRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateMembershipRole
{
    use RecordsActivity, RemediatesBugAssignments;

    /**
     * @param  array<string, mixed>  $remediation
     */
    public function handle(User $actor, MembershipRole $role, array $remediation = []): MembershipRole
    {
        return DB::transaction(function () use ($actor, $role, $remediation) {
            if ($role->role === Role::Developer) {
                $affectedBugs = Bug::open()
                    ->where('assignee_membership_id', $role->membership_id)
                    ->lockForUpdate()
                    ->get();

                $this->remediateAssignments(
                    $actor,
                    $affectedBugs,
                    $remediation,
                    'Remediation for deactivated Developer role.',
                );
            }

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
