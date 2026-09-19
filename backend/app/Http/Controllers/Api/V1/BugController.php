<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bugs\CreateBug;
use App\Actions\Bugs\UpdateBug;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Bugs\CreateBugRequest;
use App\Http\Requests\Api\V1\Bugs\UpdateBugRequest;
use App\Http\Resources\Api\V1\BugResource;
use App\Models\Bug;
use App\Models\Project;
use App\Queries\AssignedDeveloperBugs;
use App\Queries\VisibleBugs;
use Illuminate\Http\Request;

class BugController extends Controller
{
    /**
     * Only Bugs currently assigned to the authenticated Developer, via
     * their own current active grants. Never trusts a client-selected
     * workspace (FR-018).
     */
    public function assignedToMe(Request $request)
    {
        $bugs = (new AssignedDeveloperBugs())->forUser($request->user())
            ->with(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy'])
            ->orderByDesc('updated_at')
            ->get();

        return BugResource::collection($bugs);
    }

    public function store(CreateBugRequest $request, CreateBug $action)
    {
        $project = Project::findOrFail($request->validated('project_id'));

        $this->authorize('create', [Bug::class, $project]);

        $bug = $action->handle($request->user(), $project, $request->validated());

        return BugResource::make($bug)->response()->setStatusCode(201);
    }

    public function show(Request $request, Bug $bug)
    {
        abort_unless((new VisibleBugs())->forUser($request->user())->whereKey($bug->id)->exists(), 404);

        return BugResource::make($bug->load(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy']));
    }

    public function update(UpdateBugRequest $request, Bug $bug, UpdateBug $action)
    {
        abort_unless((new VisibleBugs())->forUser($request->user())->whereKey($bug->id)->exists(), 404);

        $this->authorize('update', $bug);

        $bug = $action->handle($request->user(), $bug, $request->validated());

        return BugResource::make($bug);
    }
}
