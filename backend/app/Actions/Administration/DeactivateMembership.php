<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateMembership
{
    use RecordsActivity;

    /**
     * No open Bug work can reference a membership yet (the Bug domain does
     * not exist in this phase), so there is no remediation to enforce here.
     * A later phase (T032) extends this once assignment exists.
     */
    public function handle(User $actor, Membership $membership): Membership
    {
        return DB::transaction(function () use ($actor, $membership) {
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
