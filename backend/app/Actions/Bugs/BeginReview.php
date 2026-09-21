<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\BugStatus;
use App\Models\Bug;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class BeginReview
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug): Bug
    {
        return DB::transaction(function () use ($actor, $bug) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            if ($bug->status !== BugStatus::Submitted) {
                throw new ConflictHttpException('Only a Submitted bug can begin review.');
            }

            $before = ['status' => $bug->status->value];
            $bug->status = BugStatus::Review;
            $bug->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.review_started',
                before: $before,
                after: ['status' => $bug->status->value],
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
