<?php

namespace App\Actions\Administration;

use App\Actions\Administration\Concerns\RemediatesBugAssignments;
use App\Actions\Concerns\RecordsActivity;
use App\Models\Bug;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateMembership
{
    use RecordsActivity, RemediatesBugAssignments;

    /**
     * @param  array<string, mixed>  $remediation
     */
    public function handle(User $actor, Membership $membership, array $remediation = []): Membership
    {
        return DB::transaction(function () use ($actor, $membership, $remediation) {
            $affectedBugs = Bug::open()
                ->where('assignee_membership_id', $membership->id)
                ->lockForUpdate()
                ->get();

            $this->remediateAssignments(
                $actor,
                $affectedBugs,
                $remediation,
                'Remediation for deactivated assignee Membership.',
            );

            $before = $membership->only(['is_active']);

            $membership->is_active = false;
            $membership->deactivated_at = now();
            $membership->deactivated_by_id = $actor->id;
            $membership->save();

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'membership',
                subjectId: $membership->id,
                eventType: 'membership.deactivated',
                before: $before,
                after: $membership->only(['is_active']),
                projectId: $membership->project_id,
            );

            return $membership;
        });
    }
}
