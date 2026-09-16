<?php

namespace App\Http\Controllers\Api\V1\Administration;

use App\Actions\Administration\CreateProject;
use App\Actions\Administration\DeactivateProject;
use App\Actions\Administration\UpdateProject;
use App\Http\Requests\Api\V1\Administration\CreateProjectRequest;
use App\Http\Requests\Api\V1\Administration\UpdateProjectRequest;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;

class ProjectController extends AdministrationController
{
    public function store(CreateProjectRequest $request, CreateProject $action)
    {
        $this->authorize('create', Project::class);

        $project = $action->handle($request->user(), $request->validated());

        return ProjectResource::make($project)->response()->setStatusCode(201);
    }

    public function update(
        UpdateProjectRequest $request,
        Project $project,
        UpdateProject $action,
        DeactivateProject $deactivateAction
    ) {
        $this->authorize('update', $project);

        $data = $request->validated();

        if (($data['active'] ?? null) === false) {
            $project = $deactivateAction->handle($request->user(), $project);
        } else {
            unset($data['active']);
            $project = $action->handle($request->user(), $project, $data);
        }

        return ProjectResource::make($project);
    }

    public function destroy(Request $request, Project $project)
    {
        $this->authorize('update', $project);

        return $this->rejectHardDeletion($request);
    }
}
