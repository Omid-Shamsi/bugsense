<?php

namespace App\Queries;

use App\Models\ActivityEvent;
use App\Models\Bug;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ordered, immutable per-Bug activity history (FR-030/FR-031). Visibility
 * is the caller's responsibility (see BugHistoryController), matching how
 * every other Bug-scoped query in this codebase separates "can see the Bug
 * at all" from "what does the query return".
 */
class ActivityHistoryQuery
{
    /**
     * @return Builder<ActivityEvent>
     */
    public function forBug(Bug $bug): Builder
    {
        return ActivityEvent::where('bug_id', $bug->id)->orderBy('sequence');
    }
}
