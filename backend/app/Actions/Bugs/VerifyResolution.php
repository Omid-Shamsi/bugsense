<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\BugStatus;
use App\Enums\QAVerificationDecision;
use App\Enums\Role;
use App\Models\Bug;
use App\Models\QAVerificationResult;
use App\Models\User;
use App\Support\Authorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * QA Verification → Closed (approve) or Reopened (reject). FR-022/FR-023.
 * No status/Developer/Admin override ever substitutes for an independent
 * eligible QA decision on the Bug's current active ResolutionAttempt.
 */
class VerifyResolution
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug, QAVerificationDecision $decision, string $notes): Bug
    {
        return DB::transaction(function () use ($actor, $bug, $decision, $notes) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            if ($bug->status !== BugStatus::QaVerification) {
                throw new ConflictHttpException('A resolution can only be verified while in QA Verification.');
            }

            $attempt = $bug->activeResolutionAttempt;

            if ($attempt === null || $attempt->bug_id !== $bug->id) {
                throw new ConflictHttpException('This bug has no current active resolution attempt to verify.');
            }

            if (QAVerificationResult::where('resolution_attempt_id', $attempt->id)->exists()) {
                throw new ConflictHttpException('This resolution attempt already has a QA result.');
            }

            if ($attempt->recorded_by_id === $actor->id) {
                throw new AuthorizationException('You cannot verify a resolution you recorded yourself.');
            }

            if (! Authorization::hasActiveRole($actor, $bug->project, Role::QA)) {
                throw new AuthorizationException('Only a current active project QA member may verify this resolution.');
            }

            $result = QAVerificationResult::create([
                'resolution_attempt_id' => $attempt->id,
                'verifier_id' => $actor->id,
                'decision' => $decision,
                'verification_notes' => $notes,
                'created_at' => now(),
            ]);

            $before = ['status' => $bug->status->value, 'active_resolution_attempt_id' => $bug->active_resolution_attempt_id];

            if ($decision === QAVerificationDecision::Approved) {
                $bug->status = BugStatus::Closed;
                $bug->save();

                $this->recordBugEvent(
                    bug: $bug,
                    actor: $actor,
                    eventType: 'bug.qa_approved',
                    before: $before,
                    after: ['decision' => $decision->value, 'resolution_attempt_id' => $attempt->id],
                    reasonOrResult: $notes,
                );

                // Distinct closure event required by FR-023 even though it
                // commits in the same transaction as the approval above.
                $this->recordBugEvent(
                    bug: $bug,
                    actor: $actor,
                    eventType: 'bug.closed',
                    after: ['status' => $bug->status->value],
                );
            } else {
                $bug->active_resolution_attempt_id = null;
                $bug->status = BugStatus::Reopened;
                $bug->save();

                $this->recordBugEvent(
                    bug: $bug,
                    actor: $actor,
                    eventType: 'bug.qa_rejected',
                    before: $before,
                    after: [
                        'decision' => $decision->value,
                        'status' => $bug->status->value,
                        'resolution_attempt_id' => $attempt->id,
                    ],
                    reasonOrResult: $notes,
                );
            }

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
