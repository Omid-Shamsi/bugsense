<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateProject
{
    use RecordsActivity;

    public function handle(User $actor, Project $project): Project
    {
        return DB::transaction(function () use ($actor, $project) {
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
