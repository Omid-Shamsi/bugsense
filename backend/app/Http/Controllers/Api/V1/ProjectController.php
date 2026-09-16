<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Models\Project;
use App\Queries\VisibleProjects;
use Illuminate\Http\Request;

/**
 * General, membership-scoped project discovery (FR-004). Distinct from
 * Api\V1\Administration\ProjectController, which owns the Admin-only
 * create/update/deactivate operations.
 */
class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $projects = (new VisibleProjects())->forUser($request->user())->orderBy('name')->get();

        return ProjectResource::collection($projects);
    }

    /**
     * A project outside the actor's visible set is reported as missing,
     * never as forbidden: existence itself is not disclosed (FR-004/FR-005).
     */
    public function show(Request $request, Project $project)
    {
        abort_unless($this->isVisible($request, $project), 404);

        return ProjectResource::make($project);
    }

    private function isVisible(Request $request, Project $project): bool
    {
        return (new VisibleProjects())->forUser($request->user())->whereKey($project->id)->exists();
    }
}
