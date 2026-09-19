<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\BugStatus;
use App\Enums\ResolutionOutcome;
use App\Enums\Role;
use App\Models\Bug;
use App\Models\ResolutionAttempt;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * In Progress → QA Verification (FR-019). Only the current eligible
 * assigned Developer; never closes the Bug — QA approval is Phase 8.
 */
class RecordFixedResolution
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug, string $explanation, string $qaInstructions): Bug
    {
        return DB::transaction(function () use ($actor, $bug, $explanation, $qaInstructions) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            if ($bug->status !== BugStatus::InProgress) {
                throw new ConflictHttpException('A Fixed resolution can only be recorded from In Progress.');
            }

            $membership = $bug->assigneeMembership;
            $eligible = $membership !== null
                && $membership->user_id === $actor->id
                && $membership->is_active
                && $membership->hasActiveRole(Role::Developer);

            if (! $eligible) {
                throw new AuthorizationException('Only the current eligible assigned Developer may resolve this bug.');
            }

            $nextAttemptNumber = (int) $bug->resolutionAttempts()->max('attempt_number') + 1;

            $attempt = ResolutionAttempt::create([
                'bug_id' => $bug->id,
                'attempt_number' => $nextAttemptNumber,
                'outcome' => ResolutionOutcome::Fixed,
                'recorded_by_id' => $actor->id,
                'source_status' => $bug->status->value,
                'explanation' => $explanation,
                'qa_instructions' => $qaInstructions,
                'recorded_at' => now(),
            ]);

            $before = ['status' => $bug->status->value, 'active_resolution_attempt_id' => $bug->active_resolution_attempt_id];

            $bug->active_resolution_attempt_id = $attempt->id;
            $bug->status = BugStatus::QaVerification;
            $bug->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.resolved',
                before: $before,
                after: [
                    'status' => $bug->status->value,
                    'outcome' => ResolutionOutcome::Fixed->value,
                    'attempt_number' => $attempt->attempt_number,
                    'active_resolution_attempt_id' => $attempt->id,
                ],
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
