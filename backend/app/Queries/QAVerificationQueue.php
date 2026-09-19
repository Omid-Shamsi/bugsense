<?php

namespace App\Queries;

use App\Enums\BugStatus;
use App\Enums\Role;
use App\Models\Bug;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Bugs the authenticated user can currently act on as independent QA
 * (FR-022): awaiting QA Verification, in a project where they hold an
 * active QA role, and where they did not record the active
 * ResolutionAttempt themselves. Always re-derived from current DB grants.
 */
class QAVerificationQueue
{
    /**
     * @return Builder<Bug>
     */
    public function forUser(User $user): Builder
    {
        $qaProjectIds = Membership::where('user_id', $user->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('role', Role::QA->value)->where('is_active', true))
            ->select('project_id');

        return Bug::query()
            ->where('status', BugStatus::QaVerification->value)
            ->whereIn('project_id', $qaProjectIds)
            ->whereHas('activeResolutionAttempt', fn ($q) => $q->where('recorded_by_id', '!=', $user->id));
    }
}
