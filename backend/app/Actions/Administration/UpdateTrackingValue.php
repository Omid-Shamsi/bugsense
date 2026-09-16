<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Models\TrackingValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Handles name/rank changes and reactivation only. Deactivating a tracking
 * value (active: false) is a separate, explicit operation: see
 * Administration\DeactivateTrackingValue.
 */
class UpdateTrackingValue
{
    use RecordsActivity;

    /**
     * @param  array{name?: string, rank?: ?int, active?: bool}  $data
     */
    public function handle(User $actor, TrackingValue $trackingValue, array $data): TrackingValue
    {
        return DB::transaction(function () use ($actor, $trackingValue, $data) {
            $before = $trackingValue->only(['name', 'rank', 'is_active']);

            if (array_key_exists('name', $data)) {
                $trackingValue->name = $data['name'];
            }
            if (array_key_exists('rank', $data)) {
                $trackingValue->rank = $data['rank'];
            }
            if (($data['active'] ?? false) === true && ! $trackingValue->is_active) {
                $trackingValue->is_active = true;
                $trackingValue->deactivated_at = null;
                $trackingValue->deactivated_by_id = null;
            }

            $trackingValue->save();

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'tracking_value',
                subjectId: $trackingValue->id,
                eventType: 'tracking_value.updated',
                before: $before,
                after: $trackingValue->only(['name', 'rank', 'is_active']),
                projectId: $trackingValue->project_id,
            );

            return $trackingValue;
        });
    }
}
