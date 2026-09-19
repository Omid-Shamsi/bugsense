<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bugs\AddProgressUpdate;
use App\Actions\Bugs\AssignDeveloper;
use App\Actions\Bugs\BeginReview;
use App\Actions\Bugs\RecordFixedResolution;
use App\Actions\Bugs\RecordNonFixResolution;
use App\Actions\Bugs\RenewReview;
use App\Actions\Bugs\ReopenClosedBug;
use App\Actions\Bugs\RequestInformation;
use App\Actions\Bugs\RespondToInformationRequest;
use App\Actions\Bugs\ResumeWork;
use App\Actions\Bugs\SetBugPriority;
use App\Actions\Bugs\SetBugSeverity;
use App\Actions\Bugs\StartWork;
use App\Actions\Bugs\UnassignForRemediation;
use App\Actions\Bugs\VerifyResolution;
use App\Enums\QAVerificationDecision;
use App\Enums\ResolutionOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Bugs\AddProgressUpdateRequest;
use App\Http\Requests\Api\V1\Bugs\AssignBugRequest;
use App\Http\Requests\Api\V1\Bugs\RecordNonFixResolutionRequest;
use App\Http\Requests\Api\V1\Bugs\ReopenClosedBugRequest;
use App\Http\Requests\Api\V1\Bugs\RequestInformationRequest;
use App\Http\Requests\Api\V1\Bugs\ResolveFixedRequest;
use App\Http\Requests\Api\V1\Bugs\RespondInformationRequest;
use App\Http\Requests\Api\V1\Bugs\SetPriorityRequest;
use App\Http\Requests\Api\V1\Bugs\SetSeverityRequest;
use App\Http\Requests\Api\V1\Bugs\VerifyResolutionRequest;
use App\Http\Resources\Api\V1\BugResource;
use App\Models\Bug;
use App\Queries\VisibleBugs;
use Illuminate\Http\Request;

/**
 * Explicit Phase 6 triage/assignment commands. Every command re-validates
 * visibility and authorization from the database; none trust any
 * client-supplied "current workspace" state.
 */
class BugWorkflowController extends Controller
{
    public function beginReview(Request $request, Bug $bug, BeginReview $action)
    {
        $this->authorizeAgainstVisibleBug($request, $bug, 'beginReview');

        return BugResource::make($action->handle($request->user(), $bug));
    }

    public function requestInformation(RequestInformationRequest $request, Bug $bug, RequestInformation $action)
    {
        $this->authorizeAgainstVisibleBug($request, $bug, 'requestInformation');

        return BugResource::make($action->handle($request->user(), $bug, $request->validated('request')));
    }

    public function respondToInformation(RespondInformationRequest $request, Bug $bug, RespondToInformationRequest $action)
    {
        $this->authorizeAgainstVisibleBug($request, $bug, 'respondToInformation');

        return BugResource::make($action->handle($request->user(), $bug, $request->validated('response')));
    }

    public function assign(
        AssignBugRequest $request,
        Bug $bug,
        AssignDeveloper $assign,
        UnassignForRemediation $unassign
    ) {
        $this->authorizeAgainstVisibleBug($request, $bug, 'assign');

        $assigneeId = $request->validated('assignee_id');

        $bug = $assigneeId === null
            ? $unassign->handle($request->user(), $bug, 'Explicit unassignment requested by Admin.')
            : $assign->handle($request->user(), $bug, $assigneeId);

        return BugResource::make($bug);
    }

    public function setPriority(SetPriorityRequest $request, Bug $bug, SetBugPriority $action)
    {
        $this->authorizeClassification($request, $bug);

        return BugResource::make($action->handle($request->user(), $bug, $request->validated('priority_id')));
    }

    public function setSeverity(SetSeverityRequest $request, Bug $bug, SetBugSeverity $action)
    {
        $this->authorizeClassification($request, $bug);

        return BugResource::make($action->handle($request->user(), $bug, $request->validated('severity_id')));
    }

    public function startWork(Request $request, Bug $bug, StartWork $action)
    {
        $this->authorizeAgainstVisibleBug($request, $bug, 'startWork');

        return BugResource::make($action->handle($request->user(), $bug));
    }

    public function addProgress(AddProgressUpdateRequest $request, Bug $bug, AddProgressUpdate $action)
    {
        $this->authorizeAgainstVisibleBug($request, $bug, 'addProgress');

        return BugResource::make($action->handle($request->user(), $bug, $request->validated('body')));
    }

    public function resolveFixed(ResolveFixedRequest $request, Bug $bug, RecordFixedResolution $action)
    {
        $this->authorizeAgainstVisibleBug($request, $bug, 'resolveFixed');

        return BugResource::make($action->handle(
            $request->user(),
            $bug,
            $request->validated('explanation'),
            $request->validated('qa_instructions'),
        ));
    }

    public function recordNonFix(RecordNonFixResolutionRequest $request, Bug $bug, RecordNonFixResolution $action)
    {
        $this->authorizeAgainstVisibleBug($request, $bug, 'recordNonFix');

        $bug = $action->handle(
            $request->user(),
            $bug,
            ResolutionOutcome::from($request->validated('outcome')),
            $request->validated('reason'),
            $request->validatedEvidence(),
        );

        return BugResource::make($bug);
    }

    public function verify(VerifyResolutionRequest $request, Bug $bug, VerifyResolution $action)
    {
        $this->authorizeAgainstVisibleBug($request, $bug, 'verify');

        $bug = $action->handle(
            $request->user(),
            $bug,
            QAVerificationDecision::from($request->validated('decision')),
            $request->validated('notes'),
        );

        return BugResource::make($bug);
    }

    public function resumeWork(Request $request, Bug $bug, ResumeWork $action)
    {
        $this->authorizeAgainstVisibleBug($request, $bug, 'resumeWork');

        return BugResource::make($action->handle($request->user(), $bug));
    }

    public function renewReview(Request $request, Bug $bug, RenewReview $action)
    {
        $this->authorizeAgainstVisibleBug($request, $bug, 'renewReview');

        return BugResource::make($action->handle($request->user(), $bug));
    }

    public function reopen(ReopenClosedBugRequest $request, Bug $bug, ReopenClosedBug $action)
    {
        $this->authorizeAgainstVisibleBug($request, $bug, 'reopenClosed');

        return BugResource::make($action->handle($request->user(), $bug, $request->validated('reason')));
    }

    /**
     * Invisible bugs are reported as missing, never as forbidden (FR-004/005).
     */
    private function authorizeAgainstVisibleBug(Request $request, Bug $bug, string $ability): void
    {
        abort_unless((new VisibleBugs())->forUser($request->user())->whereKey($bug->id)->exists(), 404);

        $this->authorize($ability, $bug);
    }

    private function authorizeClassification(Request $request, Bug $bug): void
    {
        abort_unless((new VisibleBugs())->forUser($request->user())->whereKey($bug->id)->exists(), 404);

        $this->authorize('setClassificationScope', [Bug::class, $bug->project]);
    }
}
