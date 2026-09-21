<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Models\Bug;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Severity is an independent classification, not a lifecycle status
 * (FR-011): it may be set or changed at any current Bug status by an Admin
 * within scope.
 */
class SetBugSeverity
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug, string $severityId): Bug
    {
        return DB::transaction(function () use ($actor, $bug, $severityId) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            $before = ['severity_id' => $bug->severity_id];
            $bug->severity_id = $severityId;
            $bug->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.severity_changed',
                before: $before,
                after: ['severity_id' => $bug->severity_id],
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
