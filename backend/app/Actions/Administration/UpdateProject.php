<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateProject
{
    use RecordsActivity;

    /**
     * @param  array{key?: string, name?: string, description?: ?string}  $data
     */
    public function handle(User $actor, Project $project, array $data): Project
    {
        return DB::transaction(function () use ($actor, $project, $data) {
            $before = $project->only(['key', 'name', 'description']);

            if (array_key_exists('key', $data)) {
                $project->key = strtoupper($data['key']);
            }
            if (array_key_exists('name', $data)) {
                $project->name = $data['name'];
            }
            if (array_key_exists('description', $data)) {
                $project->description = $data['description'];
            }

            $project->save();

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'project',
                subjectId: $project->id,
                eventType: 'project.updated',
                before: $before,
                after: $project->only(['key', 'name', 'description']),
                projectId: $project->id,
            );

            return $project;
        });
    }
}
