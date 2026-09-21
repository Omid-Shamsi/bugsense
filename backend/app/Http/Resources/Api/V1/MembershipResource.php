<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Membership */
class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'user_id' => $this->user_id,
            'user_display_name' => $this->user->display_name,
            'roles' => $this->relationLoaded('roles')
                ? $this->roles->where('is_active', true)->pluck('role')->map(fn (Role $role) => $role->value)->values()
                : $this->activeRoles()->pluck('role')->map(fn (Role $role) => $role->value)->values(),
            'active' => $this->is_active,
        ];
    }
}
