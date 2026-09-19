<?php

namespace App\Policies;

use App\Enums\BugStatus;
use App\Enums\Role;
use App\Models\Bug;
use App\Models\Project;
use App\Models\User;
use App\Queries\VisibleBugs;
use App\Support\Authorization;

class BugPolicy
{
    /**
     * Every current project member can view every Bug in that project,
     * regardless of status, reporter, or assignee (FR-004). Invisible
     * projects/bugs are handled as 404, not 403 (see BugController).
     */
    public function view(User $actor, Bug $bug): bool
    {
        return (new VisibleBugs())->forUser($actor)->whereKey($bug->id)->exists();
    }

    /**
     * Only a current project member holding the Reporter role may create a
     * Bug in that project (FR-009). Holding another role alone is not enough.
     */
    public function create(User $actor, Project $project): bool
    {
        return Authorization::hasActiveRole($actor, $project, Role::Reporter);
    }

    /**
     * The Reporter may edit their own report only while Submitted or Needs
     * Information; an Admin within scope may edit any report in scope at
     * any status (FR-013). Developer/QA roles grant no general field edit.
     */
    public function update(User $actor, Bug $bug): bool
    {
        if (Authorization::isAdminWithinScope($actor, $bug->project)) {
            return true;
        }

        if ($bug->reporter_id !== $actor->id) {
            return false;
        }

        return in_array($bug->status, [BugStatus::Submitted, BugStatus::NeedsInformation], true);
    }

    /**
     * Only an Admin within scope may set or change priority/severity,
     * including a report's initial values (FR-011) — even when the actor
     * is also the Reporter creating or editing the report.
     */
    public function setClassificationScope(User $actor, Project $project): bool
    {
        return Authorization::isAdminWithinScope($actor, $project);
    }

    /**
     * Beginning review, requesting information, and assigning/reassigning
     * are all exclusive to an Admin within scope (FR-015, FR-021). No other
     * role grants these, including a Developer or QA also holding them
     * elsewhere, or a Reporter self-assigning.
     */
    public function beginReview(User $actor, Bug $bug): bool
    {
        return Authorization::isAdminWithinScope($actor, $bug->project);
    }

    public function requestInformation(User $actor, Bug $bug): bool
    {
        return Authorization::isAdminWithinScope($actor, $bug->project);
    }

    public function assign(User $actor, Bug $bug): bool
    {
        return Authorization::isAdminWithinScope($actor, $bug->project);
    }

    /**
     * Only the Bug's own Reporter may answer its open information request
     * (FR-021); Admin authority alone does not grant this.
     */
    public function respondToInformation(User $actor, Bug $bug): bool
    {
        return $bug->reporter_id === $actor->id;
    }

    /**
     * Starting work, recording progress, and marking Fixed are all
     * exclusive to the current eligible assigned Developer (FR-018, FR-019).
     * No Admin or other role alone grants an override.
     */
    public function startWork(User $actor, Bug $bug): bool
    {
        return $this->isEligibleAssignedDeveloper($actor, $bug);
    }

    public function addProgress(User $actor, Bug $bug): bool
    {
        return $this->isEligibleAssignedDeveloper($actor, $bug);
    }

    public function resolveFixed(User $actor, Bug $bug): bool
    {
        return $this->isEligibleAssignedDeveloper($actor, $bug);
    }

    /**
     * Only an Admin within scope may record a non-fix outcome (FR-026).
     */
    public function recordNonFix(User $actor, Bug $bug): bool
    {
        return Authorization::isAdminWithinScope($actor, $bug->project);
    }

    /**
     * Independent QA (FR-022): a current active project QA member whose
     * identity differs from whoever recorded the active ResolutionAttempt.
     * System-wide Admin status never substitutes for this — an Admin who
     * also happens to hold an active QA grant may verify; Admin authority
     * alone never does.
     */
    public function verify(User $actor, Bug $bug): bool
    {
        $attempt = $bug->activeResolutionAttempt;

        if ($attempt === null) {
            return false;
        }

        if ($attempt->recorded_by_id === $actor->id) {
            return false;
        }

        return Authorization::hasActiveRole($actor, $bug->project, Role::QA);
    }

    /**
     * Only the retained eligible assigned Developer may resume a failed
     * Fixed attempt (FR-024, EC-14).
     */
    public function resumeWork(User $actor, Bug $bug): bool
    {
        return $this->isEligibleAssignedDeveloper($actor, $bug);
    }

    /**
     * Only an Admin within scope may renew review after a rejected non-fix
     * outcome or an ineligible/unassigned failed Fixed attempt (FR-024).
     */
    public function renewReview(User $actor, Bug $bug): bool
    {
        return Authorization::isAdminWithinScope($actor, $bug->project);
    }

    /**
     * Only an Admin within scope may reopen a Closed bug (FR-025).
     */
    public function reopenClosed(User $actor, Bug $bug): bool
    {
        return Authorization::isAdminWithinScope($actor, $bug->project);
    }

    /**
     * Only an Admin within scope may create or deactivate a Bug
     * relationship (FR-034).
     */
    public function manageRelationships(User $actor, Bug $bug): bool
    {
        return Authorization::isAdminWithinScope($actor, $bug->project);
    }

    private function isEligibleAssignedDeveloper(User $actor, Bug $bug): bool
    {
        $membership = $bug->assigneeMembership;

        return $membership !== null
            && $membership->user_id === $actor->id
            && $membership->is_active
            && $membership->hasActiveRole(Role::Developer);
    }
}
