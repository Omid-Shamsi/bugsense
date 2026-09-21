<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\BugStatus;
use App\Models\Bug;
use App\Models\User;
use App\Support\Authorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Closed → Review (FR-025). Like a Fixed resolution never literally resting
 * at "resolved", this never literally rests at "reopened" — the persisted
 * status jumps straight to Review while the ActivityEvent records the
 * semantic reopening. All prior ResolutionAttempts/QAVerificationResults
 * are left untouched; only the active pointer is cleared.
 */
class ReopenClosedBug
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug, string $reason): Bug
    {
        return DB::transaction(function () use ($actor, $bug, $reason) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            if ($bug->status !== BugStatus::Closed) {
                throw new ConflictHttpException('Only a Closed bug can be reopened.');
            }

            if (! Authorization::isAdminWithinScope($actor, $bug->project)) {
                throw new AuthorizationException('Only an Admin within scope may reopen a Closed bug.');
            }

            $before = ['status' => $bug->status->value, 'active_resolution_attempt_id' => $bug->active_resolution_attempt_id];

            $bug->active_resolution_attempt_id = null;
            $bug->status = BugStatus::Review;
            $bug->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.reopened',
                before: $before,
                after: ['status' => $bug->status->value],
                reasonOrResult: $reason,
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
