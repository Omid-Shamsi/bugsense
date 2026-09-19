<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Bugs\SearchBugsRequest;
use App\Http\Resources\Api\V1\BugResource;
use App\Queries\BugQuery;

/**
 * Permission-first Bug discovery (FR-035–FR-038): text/exact-ID search,
 * intersecting filters, stable sort, and bounded pagination, all composed
 * from the single BugQuery pipeline also used by DashboardController.
 */
class BugIndexController extends Controller
{
    public function index(SearchBugsRequest $request, BugQuery $bugQuery)
    {
        $query = $bugQuery->forUser($request->user());
        $query = $bugQuery->applyFilters($query, $request->bugFilters());
        $query = $bugQuery->applySort(
            $query,
            $request->string('sort', 'updated_at')->toString(),
            $request->string('direction', 'desc')->toString(),
        );

        $perPage = min((int) $request->integer('per_page', 25), 100);

        $bugs = $query
            ->with(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy'])
            ->paginate($perPage);

        return BugResource::collection($bugs);
    }
}
