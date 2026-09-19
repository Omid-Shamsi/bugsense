<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\BugStatus;
use App\Enums\QAVerificationDecision;
use App\Enums\ResolutionOutcome;
use App\Enums\Role;
use App\Models\Bug;
use App\Models\QAVerificationResult;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reopened → In Progress. Only the clarified failed-Fixed path: the latest
 * ResolutionAttempt must have been a rejected Fixed attempt, and the
 * retained assignee must still be eligible. A rejected non-fix outcome, or
 * a Fixed attempt with no eligible assignee, must go through RenewReview
 * instead — this action deliberately does not handle those cases.
 */
class ResumeWork
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug): Bug
    {
        return DB::transaction(function () use ($actor, $bug) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            if ($bug->status !== BugStatus::Reopened) {
                throw new ConflictHttpException('Work can only resume from Reopened.');
            }

            $latestAttempt = $bug->resolutionAttempts()->orderByDesc('attempt_number')->first();
            $latestResult = $latestAttempt !== null
                ? QAVerificationResult::where('resolution_attempt_id', $latestAttempt->id)->first()
                : null;

            $isRejectedFixed = $latestAttempt !== null
                && $latestAttempt->outcome === ResolutionOutcome::Fixed
                && $latestResult !== null
                && $latestResult->decision === QAVerificationDecision::Rejected;

            if (! $isRejectedFixed) {
                throw new ConflictHttpException('Only a rejected Fixed attempt may resume directly; this bug requires renewed Admin review instead.');
            }

            $membership = $bug->assigneeMembership;
            $eligible = $membership !== null
                && $membership->user_id === $actor->id
                && $membership->is_active
                && $membership->hasActiveRole(Role::Developer);

            if (! $eligible) {
                throw new AuthorizationException('Only the retained eligible assigned Developer may resume this bug.');
            }

            $before = ['status' => $bug->status->value];
            $bug->status = BugStatus::InProgress;
            $bug->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.work_resumed',
                before: $before,
                after: ['status' => $bug->status->value],
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
