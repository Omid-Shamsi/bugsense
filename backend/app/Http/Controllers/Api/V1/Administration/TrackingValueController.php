<?php

namespace App\Http\Controllers\Api\V1\Administration;

use App\Actions\Administration\CreateTrackingValue;
use App\Actions\Administration\DeactivateTrackingValue;
use App\Actions\Administration\UpdateTrackingValue;
use App\Http\Requests\Api\V1\Administration\CreateTrackingValueRequest;
use App\Http\Requests\Api\V1\Administration\UpdateTrackingValueRequest;
use App\Http\Resources\Api\V1\TrackingValueResource;
use App\Models\Project;
use App\Models\TrackingValue;
use Illuminate\Http\Request;

class TrackingValueController extends AdministrationController
{
    public function index(Request $request, Project $project)
    {
        $this->authorize('viewAny', [TrackingValue::class, $project]);

        $query = $project->trackingValues();

        if ($request->filled('kind')) {
            $query->where('kind', $request->string('kind')->toString());
        }

        return TrackingValueResource::collection($query->get());
    }

    public function store(CreateTrackingValueRequest $request, Project $project, CreateTrackingValue $action)
    {
        $this->authorize('create', [TrackingValue::class, $project]);

        $trackingValue = $this->runOrConflict(
            fn () => $action->handle($request->user(), $project, $request->validated()),
            'A tracking value with this rank already exists for this project and kind.',
        );

        return TrackingValueResource::make($trackingValue)->response()->setStatusCode(201);
    }

    public function update(
        UpdateTrackingValueRequest $request,
        TrackingValue $trackingValue,
        UpdateTrackingValue $action,
        DeactivateTrackingValue $deactivateAction
    ) {
        $this->authorize('update', $trackingValue);

        $data = $request->validated();

        if (($data['active'] ?? null) === false) {
            $trackingValue = $deactivateAction->handle($request->user(), $trackingValue, (array) $request->input('remediation', []));
        } else {
            $trackingValue = $this->runOrConflict(
                fn () => $action->handle($request->user(), $trackingValue, $data),
                'A tracking value with this rank already exists for this project and kind.',
            );
        }

        return TrackingValueResource::make($trackingValue);
    }

    public function destroy(Request $request, TrackingValue $trackingValue)
    {
        $this->authorize('update', $trackingValue);

        return $this->rejectHardDeletion($request);
    }
}
