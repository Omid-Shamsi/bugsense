<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\BugStatus;
use App\Models\Bug;
use App\Models\InformationRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RequestInformation
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug, string $requestText): Bug
    {
        return DB::transaction(function () use ($actor, $bug, $requestText) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            if ($bug->status !== BugStatus::Review) {
                throw new ConflictHttpException('Information can only be requested from Review.');
            }

            $before = ['status' => $bug->status->value];

            InformationRequest::create([
                'bug_id' => $bug->id,
                'requested_by_id' => $actor->id,
                'request_text' => $requestText,
                'requested_at' => now(),
            ]);

            $bug->status = BugStatus::NeedsInformation;
            $bug->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.information_requested',
                before: $before,
                after: ['status' => $bug->status->value],
                reasonOrResult: $requestText,
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
