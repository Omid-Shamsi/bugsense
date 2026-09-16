<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\Role;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CreateMembership
{
    use RecordsActivity;

    /**
     * @param  array{user_id: string, roles: list<string>}  $data
     */
    public function handle(User $actor, Project $project, array $data): Membership
    {
        $roles = array_map(fn (string $role) => Role::from($role), $data['roles']);

        if (in_array(Role::Admin, $roles, true) && ! \App\Support\Authorization::isSystemAdmin($actor)) {
            throw new AuthorizationException('Only a system-wide Admin may grant the Admin role.');
        }

        return DB::transaction(function () use ($actor, $project, $data, $roles) {
            $membership = Membership::create([
                'project_id' => $project->id,
                'user_id' => $data['user_id'],
                'joined_at' => now(),
            ]);

            foreach ($roles as $role) {
                $membership->roles()->create([
                    'role' => $role,
                    'granted_at' => now(),
                    'granted_by_id' => $actor->id,
                ]);
            }

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'membership',
                subjectId: $membership->id,
                eventType: 'membership.created',
                after: [
                    'user_id' => $membership->user_id,
                    'roles' => array_map(fn (Role $role) => $role->value, $roles),
                ],
                projectId: $project->id,
            );

            return $membership->load('roles');
        });
    }
}
