<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\Role;
use App\Models\Membership;
use App\Models\User;
use App\Support\Authorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Handles role-set changes and reactivation only. Deactivating a
 * membership (active: false) is a separate, explicit operation:
 * see Administration\DeactivateMembership.
 */
class UpdateMembership
{
    use RecordsActivity;

    /**
     * @param  array{roles?: list<string>, active?: bool}  $data
     */
    public function handle(User $actor, Membership $membership, array $data): Membership
    {
        $requestedRoles = isset($data['roles'])
            ? array_map(fn (string $role) => Role::from($role), $data['roles'])
            : null;

        $currentActiveRoles = $membership->activeRoles()->pluck('role')->all();

        $toGrant = [];
        $toRevoke = [];

        if ($requestedRoles !== null) {
            $toGrant = array_values(array_udiff(
                $requestedRoles,
                $currentActiveRoles,
                fn (Role $a, Role $b) => $a->value <=> $b->value,
            ));

            $toRevoke = array_values(array_udiff(
                $currentActiveRoles,
                $requestedRoles,
                fn (Role $a, Role $b) => $a->value <=> $b->value,
            ));

            $touchesAdmin = in_array(Role::Admin, [...$toGrant, ...$toRevoke], true);

            if ($touchesAdmin && ! Authorization::isSystemAdmin($actor)) {
                throw new AuthorizationException('Only a system-wide Admin may change the Admin role.');
            }
        }

        return DB::transaction(function () use ($actor, $membership, $data, $toGrant, $toRevoke, $currentActiveRoles) {
            $before = [
                'roles' => array_map(fn (Role $r) => $r->value, $currentActiveRoles),
                'active' => $membership->is_active,
            ];

            foreach ($toGrant as $role) {
                $roleRow = $membership->roles()->firstOrNew(['role' => $role]);
                $roleRow->is_active = true;
                $roleRow->granted_at = now();
                $roleRow->granted_by_id = $actor->id;
                $roleRow->deactivated_at = null;
                $roleRow->deactivated_by_id = null;
                $roleRow->save();
            }

            foreach ($toRevoke as $role) {
                $roleRow = $membership->roles()->where('role', $role->value)->where('is_active', true)->first();
                if ($roleRow !== null) {
                    app(DeactivateMembershipRole::class)->handle($actor, $roleRow, (array) ($data['remediation'] ?? []));
                }
            }

            if (($data['active'] ?? false) === true && ! $membership->is_active) {
                $membership->is_active = true;
                $membership->deactivated_at = null;
                $membership->deactivated_by_id = null;
                $membership->save();
            }

            $membership->refresh();

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'membership',
                subjectId: $membership->id,
                eventType: 'membership.updated',
                before: $before,
                after: [
                    'roles' => $membership->activeRoles()->pluck('role')->map(fn (Role $r) => $r->value)->all(),
                    'active' => $membership->is_active,
                ],
                projectId: $membership->project_id,
            );

            return $membership->load('roles');
        });
    }
}
