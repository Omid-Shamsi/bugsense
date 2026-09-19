<?php

namespace App\Actions\Administration;

use App\Actions\Administration\Concerns\RemediatesBugClassifications;
use App\Actions\Concerns\RecordsActivity;
use App\Models\TrackingValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateTrackingValue
{
    use RecordsActivity, RemediatesBugClassifications;

    /**
     * @param  array<string, mixed>  $remediation
     */
    public function handle(User $actor, TrackingValue $trackingValue, array $remediation = []): TrackingValue
    {
        return DB::transaction(function () use ($actor, $trackingValue, $remediation) {
            $this->remediateClassification($actor, $trackingValue, $remediation);

            $before = $trackingValue->only(['is_active']);

            $trackingValue->is_active = false;
            $trackingValue->deactivated_at = now();
            $trackingValue->deactivated_by_id = $actor->id;
            $trackingValue->save();

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'tracking_value',
                subjectId: $trackingValue->id,
                eventType: 'tracking_value.deactivated',
                before: $before,
                after: $trackingValue->only(['is_active']),
                projectId: $trackingValue->project_id,
            );

            return $trackingValue;
        });
    }
}
