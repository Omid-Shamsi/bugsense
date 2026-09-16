<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateProject
{
    use RecordsActivity;

    /**
     * @param  array{key: string, name: string, description?: ?string}  $data
     */
    public function handle(User $actor, array $data): Project
    {
        return DB::transaction(function () use ($actor, $data) {
            $project = Project::create([
                'key' => strtoupper($data['key']),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'project',
                subjectId: $project->id,
                eventType: 'project.created',
                after: $project->only(['key', 'name', 'description']),
                projectId: $project->id,
            );

            return $project;
        });
    }
}
