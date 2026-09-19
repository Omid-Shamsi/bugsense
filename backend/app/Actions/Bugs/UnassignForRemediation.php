<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\BugStatus;
use App\Models\Bug;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Explicitly unassign a Bug to remediate an assignee who is about to become,
 * or already is, ineligible (FR-008, FR-017, EC-02). This is the only path
 * that clears assignee_membership_id; it always returns the Bug to Review.
 * Used both from the assignments command (assignee_id: null) and internally
 * by Deactivate* remediation.
 */
class UnassignForRemediation
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug, string $reason = 'Assignee is no longer eligible.'): Bug
    {
        return DB::transaction(function () use ($actor, $bug, $reason) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            if (! in_array($bug->status, [BugStatus::Assigned, BugStatus::InProgress], true)) {
                throw new ConflictHttpException('Only an Assigned or In Progress bug can be unassigned for remediation.');
            }

            $before = [
                'assignee_membership_id' => $bug->assignee_membership_id,
                'status' => $bug->status->value,
            ];

            $bug->assignee_membership_id = null;
            $bug->status = BugStatus::Review;
            $bug->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.unassigned_for_remediation',
                before: $before,
                after: ['assignee_membership_id' => null, 'status' => $bug->status->value],
                reasonOrResult: $reason,
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
