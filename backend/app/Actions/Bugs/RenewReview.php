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
use App\Support\Authorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reopened → Review, for a rejected non-fix outcome or a failed Fixed
 * attempt with no eligible assignee remaining (FR-024). Admin within scope
 * only. Deliberately makes no assignment change of its own — an Admin must
 * separately assign an eligible Developer afterward (no hidden side effects).
 */
class RenewReview
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug): Bug
    {
        return DB::transaction(function () use ($actor, $bug) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            if ($bug->status !== BugStatus::Reopened) {
                throw new ConflictHttpException('Review can only be renewed from Reopened.');
            }

            if (! Authorization::isAdminWithinScope($actor, $bug->project)) {
                throw new AuthorizationException('Only an Admin within scope may renew review.');
            }

            $latestAttempt = $bug->resolutionAttempts()->orderByDesc('attempt_number')->first();
            $latestResult = $latestAttempt !== null
                ? QAVerificationResult::where('resolution_attempt_id', $latestAttempt->id)->first()
                : null;

            $membership = $bug->assigneeMembership;
            $assigneeEligible = $membership !== null && $membership->is_active && $membership->hasActiveRole(Role::Developer);

            $isRejectedFixedWithEligibleAssignee = $latestAttempt !== null
                && $latestAttempt->outcome === ResolutionOutcome::Fixed
                && $latestResult !== null
                && $latestResult->decision === QAVerificationDecision::Rejected
                && $assigneeEligible;

            if ($isRejectedFixedWithEligibleAssignee) {
                throw new ConflictHttpException('This bug has an eligible assignee who must resume work directly instead of renewed review.');
            }

            $before = ['status' => $bug->status->value];
            $bug->status = BugStatus::Review;
            $bug->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.review_renewed',
                before: $before,
                after: ['status' => $bug->status->value],
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
