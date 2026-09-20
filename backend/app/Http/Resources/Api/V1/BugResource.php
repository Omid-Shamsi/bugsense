<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\BugStatus;
use App\Policies\BugPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Bug */
class BugResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'project' => ProjectResource::make($this->project),
            'reporter' => UserSummaryResource::make($this->reporter),
            'assignee' => $this->assigneeMembership ? UserSummaryResource::make($this->assigneeMembership->user) : null,
            'title' => $this->title,
            'description' => $this->description,
            'steps_to_reproduce' => $this->steps_to_reproduce,
            'expected_result' => $this->expected_result,
            'actual_result' => $this->actual_result,
            'environment' => $this->environment,
            'platform' => $this->platform,
            'application_version' => $this->application_version,
            'status' => $this->status->value,
            'category' => $this->category ? TrackingValueResource::make($this->category) : null,
            'priority' => $this->priority ? TrackingValueResource::make($this->priority) : null,
            'severity' => $this->severity ? TrackingValueResource::make($this->severity) : null,
            'tags' => TrackingValueResource::collection($this->tags),
            'active_resolution' => $this->activeResolutionAttempt ? ResolutionAttemptResource::make($this->activeResolutionAttempt) : null,
            'open_information_request' => $this->openInformationRequest ? [
                'request_text' => $this->openInformationRequest->request_text,
                'requested_at' => $this->openInformationRequest->requested_at,
                'requested_by' => UserSummaryResource::make($this->openInformationRequest->requestedBy),
            ] : null,
            // Prior attempts stay readable here even after active_resolution
            // is cleared on rejection/reopen — this is the Phase 7/8
            // resolution record itself, not the Phase 9 activity timeline.
            'resolution_attempts' => ResolutionAttemptResource::collection($this->resolutionAttempts),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'allowed_actions' => $this->allowedActions($request),
        ];
    }

    /**
     * A presentation hint only. Laravel independently re-authorizes every
     * request; the frontend must not treat this list as authoritative. Only
     * actions actually implemented through this phase are ever advertised.
     *
     * @return list<string>
     */
    private function allowedActions(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        /** @var \App\Models\Bug $bug */
        $bug = $this->resource;
        $policy = new BugPolicy();
        $actions = [];

        if ($policy->update($user, $bug)) {
            $actions[] = 'edit';
        }

        if ($bug->status === BugStatus::Submitted && $policy->beginReview($user, $bug)) {
            $actions[] = 'begin_review';
        }

        if ($bug->status === BugStatus::Review && $policy->requestInformation($user, $bug)) {
            $actions[] = 'request_information';
        }

        if ($bug->status === BugStatus::NeedsInformation && $policy->respondToInformation($user, $bug)) {
            $actions[] = 'respond_information';
        }

        if (in_array($bug->status, [BugStatus::Review, BugStatus::Assigned], true) && $policy->assign($user, $bug)) {
            $actions[] = 'assign';
        }

        if ($policy->setClassificationScope($user, $bug->project)) {
            $actions[] = 'set_priority';
            $actions[] = 'set_severity';
        }

        if ($bug->status === BugStatus::Assigned && $policy->startWork($user, $bug)) {
            $actions[] = 'start_work';
        }

        if ($bug->status === BugStatus::InProgress && $policy->addProgress($user, $bug)) {
            $actions[] = 'add_progress';
        }

        if ($bug->status === BugStatus::InProgress && $policy->resolveFixed($user, $bug)) {
            $actions[] = 'resolve_fixed';
        }

        if (
            in_array($bug->status, [BugStatus::Review, BugStatus::Assigned, BugStatus::InProgress], true)
            && $policy->recordNonFix($user, $bug)
        ) {
            $actions[] = 'resolve_non_fix';
        }

        if ($bug->status === BugStatus::QaVerification && $policy->verify($user, $bug)) {
            $actions[] = 'verify';
        }

        if ($bug->status === BugStatus::Reopened && $policy->resumeWork($user, $bug)) {
            $actions[] = 'resume_work';
        }

        if ($bug->status === BugStatus::Reopened && $policy->renewReview($user, $bug)) {
            $actions[] = 'renew_review';
        }

        if ($bug->status === BugStatus::Closed && $policy->reopenClosed($user, $bug)) {
            $actions[] = 'reopen';
        }

        return $actions;
    }
}
