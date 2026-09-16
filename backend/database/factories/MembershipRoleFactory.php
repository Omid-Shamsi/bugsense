<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MembershipRole>
 */
class MembershipRoleFactory extends Factory
{
    protected $model = MembershipRole::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'membership_id' => Membership::factory(),
            'role' => Role::Reporter,
            'granted_at' => now(),
            'granted_by_id' => User::factory(),
        ];
    }
}
