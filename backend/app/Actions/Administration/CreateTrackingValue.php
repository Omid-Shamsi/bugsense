<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Models\Project;
use App\Models\TrackingValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTrackingValue
{
    use RecordsActivity;

    /**
     * @param  array{kind: string, code: string, name: string, rank?: ?int}  $data
     */
    public function handle(User $actor, Project $project, array $data): TrackingValue
    {
        return DB::transaction(function () use ($actor, $project, $data) {
            $trackingValue = TrackingValue::create([
                'project_id' => $project->id,
                'kind' => $data['kind'],
                'code' => $data['code'],
                'name' => $data['name'],
                'rank' => $data['rank'] ?? null,
            ]);

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'tracking_value',
                subjectId: $trackingValue->id,
                eventType: 'tracking_value.created',
                after: $trackingValue->only(['kind', 'code', 'name', 'rank']),
                projectId: $project->id,
            );

            return $trackingValue;
        });
    }
}
