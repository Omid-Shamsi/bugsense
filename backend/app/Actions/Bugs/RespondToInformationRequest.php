<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\BugStatus;
use App\Models\Bug;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RespondToInformationRequest
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug, string $responseText): Bug
    {
        return DB::transaction(function () use ($actor, $bug, $responseText) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            if ($bug->status !== BugStatus::NeedsInformation) {
                throw new ConflictHttpException('A response is only accepted while Needs Information.');
            }

            $openRequest = $bug->informationRequests()->whereNull('responded_at')->latest('requested_at')->first();

            if ($openRequest === null) {
                throw new ConflictHttpException('No open information request to respond to.');
            }

            $before = ['status' => $bug->status->value];

            $openRequest->responded_by_id = $actor->id;
            $openRequest->response_text = $responseText;
            $openRequest->responded_at = now();
            $openRequest->save();

            $bug->status = BugStatus::Review;
            $bug->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.information_responded',
                before: $before,
                after: ['status' => $bug->status->value],
                reasonOrResult: $responseText,
            );

            return $bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']);
        });
    }
}
