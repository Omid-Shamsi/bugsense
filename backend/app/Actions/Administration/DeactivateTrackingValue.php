<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Models\TrackingValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateTrackingValue
{
    use RecordsActivity;

    /**
     * No Bug can reference a tracking value yet (the Bug domain does not
     * exist in this phase), so there is no open-work remediation to enforce
     * here. A later phase (T032) extends this once Bugs exist.
     */
    public function handle(User $actor, TrackingValue $trackingValue): TrackingValue
    {
        return DB::transaction(function () use ($actor, $trackingValue) {
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
