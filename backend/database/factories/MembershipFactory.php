<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
{
    protected $model = Membership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'user_id' => User::factory(),
            'joined_at' => now(),
        ];
    }

    public function withRole(Role $role, ?User $grantedBy = null): static
    {
        return $this->afterCreating(function (Membership $membership) use ($role, $grantedBy) {
            $membership->roles()->create([
                'role' => $role,
                'granted_at' => now(),
                'granted_by_id' => ($grantedBy ?? $membership->user)->id,
            ]);
        });
    }
}
