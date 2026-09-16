<?php

namespace App\Http\Controllers\Api\V1\Administration;

use App\Actions\Administration\CreateMembership;
use App\Actions\Administration\DeactivateMembership;
use App\Actions\Administration\UpdateMembership;
use App\Http\Requests\Api\V1\Administration\CreateMembershipRequest;
use App\Http\Requests\Api\V1\Administration\UpdateMembershipRequest;
use App\Http\Resources\Api\V1\MembershipResource;
use App\Models\Membership;
use App\Models\Project;
use Illuminate\Http\Request;

class MembershipController extends AdministrationController
{
    public function index(Project $project)
    {
        $this->authorize('viewAny', [Membership::class, $project]);

        $memberships = $project->memberships()->with('roles')->get();

        return MembershipResource::collection($memberships);
    }

    public function store(CreateMembershipRequest $request, Project $project, CreateMembership $action)
    {
        $this->authorize('create', [Membership::class, $project]);

        $membership = $this->runOrConflict(
            fn () => $action->handle($request->user(), $project, $request->validated()),
            'This user already has a membership in this project.',
        );

        return MembershipResource::make($membership)->response()->setStatusCode(201);
    }

    public function update(
        UpdateMembershipRequest $request,
        Membership $membership,
        UpdateMembership $action,
        DeactivateMembership $deactivateAction
    ) {
        $this->authorize('update', $membership);

        $data = $request->validated();

        if (($data['active'] ?? null) === false) {
            $membership = $deactivateAction->handle($request->user(), $membership);
        } else {
            $membership = $action->handle($request->user(), $membership, $data);
        }

        return MembershipResource::make($membership->load('roles'));
    }

    public function destroy(Request $request, Membership $membership)
    {
        $this->authorize('update', $membership);

        return $this->rejectHardDeletion($request);
    }
}
