<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Relationships\CreateBugRelationship;
use App\Actions\Relationships\DeactivateBugRelationship;
use App\Enums\BugRelationshipType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Bugs\CreateBugRelationshipRequest;
use App\Http\Resources\Api\V1\BugRelationshipResource;
use App\Models\Bug;
use App\Models\BugRelationship;
use App\Queries\VisibleBugs;
use Illuminate\Http\Request;

class BugRelationshipController extends Controller
{
    /**
     * Only active relationships whose OTHER endpoint is also currently
     * visible to the actor are listed — an inactive or cross-visibility
     * link never discloses an inaccessible Bug (FR-033).
     */
    public function index(Request $request, Bug $bug)
    {
        abort_unless((new VisibleBugs())->forUser($request->user())->whereKey($bug->id)->exists(), 404);

        $visibleBugIds = (new VisibleBugs())->forUser($request->user())->select('id');

        $relationships = BugRelationship::where('is_active', true)
            ->where(function ($query) use ($bug) {
                $query->where('source_bug_id', $bug->id)->orWhere('target_bug_id', $bug->id);
            })
            ->where(function ($query) use ($visibleBugIds) {
                $query->whereIn('source_bug_id', $visibleBugIds)->whereIn('target_bug_id', $visibleBugIds);
            })
            ->with(['sourceBug', 'targetBug', 'createdBy'])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $relationships
                ->map(fn (BugRelationship $relationship) => (new BugRelationshipResource($relationship, $bug->id))->toArray($request))
                ->values(),
        ]);
    }

    public function store(CreateBugRelationshipRequest $request, Bug $bug, CreateBugRelationship $action)
    {
        abort_unless((new VisibleBugs())->forUser($request->user())->whereKey($bug->id)->exists(), 404);
        $this->authorize('manageRelationships', $bug);

        $relationship = $action->handle(
            $request->user(),
            $bug,
            BugRelationshipType::from($request->validated('type')),
            $request->resolvedTarget(),
        );

        return (new BugRelationshipResource($relationship, $bug->id))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, BugRelationship $relationship, DeactivateBugRelationship $action)
    {
        $sourceBug = $relationship->sourceBug;
        abort_unless((new VisibleBugs())->forUser($request->user())->whereKey($sourceBug->id)->exists(), 404);
        $this->authorize('manageRelationships', $sourceBug);

        $action->handle($request->user(), $relationship);

        return response()->noContent();
    }
}
