<?php

namespace App\Actions\Administration;

use App\Actions\Administration\Concerns\RemediatesBugAssignments;
use App\Actions\Concerns\RecordsActivity;
use App\Models\Bug;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateUser
{
    use RecordsActivity, RemediatesBugAssignments;

    /**
     * @param  array<string, mixed>  $remediation
     */
    public function handle(User $actor, User $target, array $remediation = []): User
    {
        return DB::transaction(function () use ($actor, $target, $remediation) {
            $membershipIds = Membership::where('user_id', $target->id)->pluck('id');

            $affectedBugs = Bug::open()
                ->whereIn('assignee_membership_id', $membershipIds)
                ->lockForUpdate()
                ->get();

            $this->remediateAssignments(
                $actor,
                $affectedBugs,
                $remediation,
                'Remediation for deactivated assignee User.',
            );

            $before = $target->only(['is_active']);

            $target->is_active = false;
            $target->deactivated_at = now();
            $target->deactivated_by_id = $actor->id;
            $target->save();

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'user',
                subjectId: $target->id,
                eventType: 'user.deactivated',
                before: $before,
                after: $target->only(['is_active']),
            );

            return $target;
        });
    }
}
