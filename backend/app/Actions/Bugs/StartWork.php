<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\BugStatus;
use App\Enums\Role;
use App\Models\Bug;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Assigned → In Progress (FR-018). A current eligible Developer assignment
 * is the only readiness condition — re-checked here, not trusted from
 * whatever the client last saw.
 */
class StartWork
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug): Bug
    {
        return DB::transaction(function () use ($actor, $bug) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            if ($bug->status !== BugStatus::Assigned) {
                throw new ConflictHttpException('Only an Assigned bug can start work.');
            }

            $membership = $bug->assigneeMembership;
            $eligible = $membership !== null
                && $membership->user_id === $actor->id
                && $membership->is_active
                && $membership->hasActiveRole(Role::Developer);

            if (! $eligible) {
                throw new AuthorizationException('Only the current eligible assigned Developer may start work.');
            }

            $before = ['status' => $bug->status->value];
            $bug->status = BugStatus::InProgress;
            $bug->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.work_started',
                before: $before,
                after: ['status' => $bug->status->value],
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
