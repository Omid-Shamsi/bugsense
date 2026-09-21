<?php

namespace App\Queries;

use App\Models\Bug;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Permission-filtered Bug visibility: every current project member can view
 * every Bug in that project, regardless of status, reporter, or assignee
 * (FR-004). Built on VisibleProjects so the project-visibility rule can
 * never drift between the two queries.
 */
class VisibleBugs
{
    /**
     * @return Builder<Bug>
     */
    public function forUser(User $user): Builder
    {
        return Bug::query()->whereIn(
            'bugs.project_id',
            (new VisibleProjects())->forUser($user)->select('projects.id'),
        );
    }
}
