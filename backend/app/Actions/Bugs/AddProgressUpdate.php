<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\BugStatus;
use App\Enums\Role;
use App\Models\Bug;
use App\Models\ProgressUpdate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Append-only progress note from the current eligible assigned Developer
 * (FR-018). Does not change status; distinct from the ActivityEvent it
 * also records (data-model.md: "do not replace ActivityEvents").
 */
class AddProgressUpdate
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug, string $body): Bug
    {
        return DB::transaction(function () use ($actor, $bug, $body) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            if ($bug->status !== BugStatus::InProgress) {
                throw new ConflictHttpException('Progress can only be recorded while In Progress.');
            }

            $membership = $bug->assigneeMembership;
            $eligible = $membership !== null
                && $membership->user_id === $actor->id
                && $membership->is_active
                && $membership->hasActiveRole(Role::Developer);

            if (! $eligible) {
                throw new AuthorizationException('Only the current eligible assigned Developer may record progress.');
            }

            $progress = ProgressUpdate::create([
                'bug_id' => $bug->id,
                'author_id' => $actor->id,
                'body' => $body,
                'created_at' => now(),
            ]);

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.progress_recorded',
                after: ['progress_update_id' => $progress->id],
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
