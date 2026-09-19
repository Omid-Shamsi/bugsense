<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Actions\Relationships\EnsureDuplicateRelationship;
use App\Enums\BugStatus;
use App\Enums\ResolutionOutcome;
use App\Models\Bug;
use App\Models\ResolutionAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Admin-only non-fix disposition (FR-026/FR-027): Duplicate, Cannot
 * Reproduce, or Won't Fix. Denied from every status but Review, Assigned,
 * and In Progress. Hands off to QA Verification like Fixed; never closes.
 */
class RecordNonFixResolution
{
    use RecordsActivity;

    private const ALLOWED_SOURCE_STATUSES = [
        BugStatus::Review,
        BugStatus::Assigned,
        BugStatus::InProgress,
    ];

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function handle(User $actor, Bug $bug, ResolutionOutcome $outcome, string $reason, array $evidence = []): Bug
    {
        return DB::transaction(function () use ($actor, $bug, $outcome, $reason, $evidence) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            if (! in_array($bug->status, self::ALLOWED_SOURCE_STATUSES, true)) {
                throw new ConflictHttpException('A non-fix outcome can only be recorded from Review, Assigned, or In Progress.');
            }

            $attributes = [
                'bug_id' => $bug->id,
                'outcome' => $outcome,
                'recorded_by_id' => $actor->id,
                'source_status' => $bug->status->value,
                'explanation' => $reason,
                'recorded_at' => now(),
            ];

            $attributes = match ($outcome) {
                ResolutionOutcome::Duplicate => $attributes + [
                    'duplicate_relationship_id' => $this->ensureDuplicateRelationship($actor, $bug, $evidence),
                ],
                ResolutionOutcome::CannotReproduce => $attributes + [
                    'reproduction_attempts' => $evidence['attempted_steps'] ?? null,
                    'reproduction_environment' => $evidence['environment'] ?? null,
                ],
                ResolutionOutcome::WontFix => $attributes + [
                    'decision_rationale' => $evidence['decision_rationale'] ?? null,
                ],
                ResolutionOutcome::Fixed => throw ValidationException::withMessages([
                    'outcome' => 'Fixed is recorded through the dedicated Fixed-resolution command.',
                ]),
            };

            $nextAttemptNumber = (int) $bug->resolutionAttempts()->max('attempt_number') + 1;
            $attributes['attempt_number'] = $nextAttemptNumber;

            $attempt = ResolutionAttempt::create($attributes);

            $before = ['status' => $bug->status->value, 'active_resolution_attempt_id' => $bug->active_resolution_attempt_id];

            $bug->active_resolution_attempt_id = $attempt->id;
            $bug->status = BugStatus::QaVerification;
            $bug->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.non_fix_recorded',
                before: $before,
                after: [
                    'status' => $bug->status->value,
                    'outcome' => $outcome->value,
                    'attempt_number' => $attempt->attempt_number,
                    'active_resolution_attempt_id' => $attempt->id,
                ],
                reasonOrResult: $reason,
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    private function ensureDuplicateRelationship(User $actor, Bug $bug, array $evidence): string
    {
        $target = $evidence['duplicate_target'] ?? null;

        if (! $target instanceof Bug) {
            throw ValidationException::withMessages([
                'duplicate_bug_id' => 'A valid original bug is required for a Duplicate outcome.',
            ]);
        }

        $relationship = app(EnsureDuplicateRelationship::class)->handle($actor, $bug, $target);

        return $relationship->id;
    }
}
