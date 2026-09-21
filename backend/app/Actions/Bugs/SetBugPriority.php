<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Models\Bug;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Priority is an independent classification, not a lifecycle status
 * (FR-011): it may be set or changed at any current Bug status by an Admin
 * within scope.
 */
class SetBugPriority
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug, string $priorityId): Bug
    {
        return DB::transaction(function () use ($actor, $bug, $priorityId) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            $before = ['priority_id' => $bug->priority_id];
            $bug->priority_id = $priorityId;
            $bug->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.priority_changed',
                before: $before,
                after: ['priority_id' => $bug->priority_id],
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
