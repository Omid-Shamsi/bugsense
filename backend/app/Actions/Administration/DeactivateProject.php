<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Models\Bug;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DeactivateProject
{
    use RecordsActivity;

    public function handle(User $actor, Project $project): Project
    {
        return DB::transaction(function () use ($actor, $project) {
            // Postgres rejects FOR UPDATE combined with an aggregate (count());
            // lock and fetch the rows themselves instead.
            $openBugs = Bug::open()->where('project_id', $project->id)->lockForUpdate()->get();

            if ($openBugs->isNotEmpty()) {
                throw new ConflictHttpException(sprintf(
                    '%d open bug(s) remain in this project; resolve or close them before deactivating the project.',
                    $openBugs->count(),
                ));
            }

            $before = $project->only(['is_active']);

            $project->is_active = false;
            $project->deactivated_at = now();
            $project->deactivated_by_id = $actor->id;
            $project->save();

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'project',
                subjectId: $project->id,
                eventType: 'project.deactivated',
                before: $before,
                after: $project->only(['is_active']),
                projectId: $project->id,
            );

            return $project;
        });
    }
}
