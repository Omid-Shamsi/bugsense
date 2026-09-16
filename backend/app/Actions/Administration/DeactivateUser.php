<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeactivateUser
{
    use RecordsActivity;

    public function handle(User $actor, User $target): User
    {
        return DB::transaction(function () use ($actor, $target) {
            $before = $target->only(['is_active']);

            $target->is_active = false;
            $target->deactivated_at = now();
            $target->deactivated_by_id = $actor->id;
            $target->save();

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'user',
                subjectId: $target->id,
                eventType: 'user.deactivated',
                before: $before,
                after: $target->only(['is_active']),
            );

            return $target;
        });
    }
}
