<?php

namespace App\Actions\Administration\Concerns;

use App\Actions\Bugs\AssignDeveloper;
use App\Actions\Bugs\UnassignForRemediation;
use App\Models\Bug;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Shared remediation gate for deactivation Actions (FR-008, EC-02): before a
 * referenced record is deactivated, every open Bug currently assigned
 * through it must be explicitly reassigned or unassigned first. Never
 * silently orphans an assignment.
 */
trait RemediatesBugAssignments
{
    /**
     * @param  Collection<int, Bug>  $affectedBugs
     * @param  array<string, mixed>  $remediation
     */
    protected function remediateAssignments(User $actor, Collection $affectedBugs, array $remediation, string $reason): void
    {
        if ($affectedBugs->isEmpty()) {
            return;
        }

        if (($remediation['unassign'] ?? false) === true) {
            foreach ($affectedBugs as $bug) {
                app(UnassignForRemediation::class)->handle($actor, $bug, $reason);
            }

            return;
        }

        if (! empty($remediation['reassign_to'])) {
            foreach ($affectedBugs as $bug) {
                app(AssignDeveloper::class)->handle($actor, $bug, $remediation['reassign_to']);
            }

            return;
        }

        throw new ConflictHttpException(sprintf(
            '%d open bug(s) are currently assigned through this record (%s); reassign or unassign them first.',
            $affectedBugs->count(),
            $affectedBugs->pluck('public_id')->implode(', '),
        ));
    }
}
