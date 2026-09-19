<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\BugStatus;
use App\Enums\Role;
use App\Models\Bug;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Assign or reassign the current Developer (FR-015–FR-017). The assignee is
 * stored as a Membership id, never a raw User id, since eligibility is a
 * property of the membership (same project, active, active Developer role)
 * rather than of the user in the abstract.
 */
class AssignDeveloper
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug, string $assigneeUserId): Bug
    {
        return DB::transaction(function () use ($actor, $bug, $assigneeUserId) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            if (! in_array($bug->status, [BugStatus::Review, BugStatus::Assigned], true)) {
                throw new ConflictHttpException('A Developer can only be assigned from Review or Assigned.');
            }

            $newMembership = Membership::where('project_id', $bug->project_id)
                ->where('user_id', $assigneeUserId)
                ->where('is_active', true)
                ->first();

            $eligible = $newMembership !== null && $newMembership->hasActiveRole(Role::Developer);

            if (! $eligible) {
                throw ValidationException::withMessages([
                    'assignee_id' => 'The selected user is not an active Developer in this project.',
                ]);
            }

            $before = ['assignee_membership_id' => $bug->assignee_membership_id];

            $bug->assignee_membership_id = $newMembership->id;
            $bug->status = BugStatus::Assigned;
            $bug->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.assigned',
                before: $before,
                after: ['assignee_membership_id' => $bug->assignee_membership_id],
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
