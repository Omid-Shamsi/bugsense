<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Bugs\DashboardFiltersRequest;
use App\Http\Resources\Api\V1\DashboardResource;
use App\Queries\DashboardQuery;

/**
 * Dashboard counts/breakdowns/resolution-time, using the same visibility
 * and filter semantics as the Bug list (FR-039) — no separate authorization
 * model for dashboards.
 */
class DashboardController extends Controller
{
    public function summary(DashboardFiltersRequest $request, DashboardQuery $dashboardQuery)
    {
        $summary = $dashboardQuery->summarize($request->user(), $request->bugFilters());

        return DashboardResource::make($summary);
    }
}
