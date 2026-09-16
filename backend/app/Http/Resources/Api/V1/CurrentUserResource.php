<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\Role;
use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class CurrentUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'display_name' => $this->display_name,
            'is_system_admin' => $this->is_system_admin,
            'memberships' => $this->memberships()
                ->where('is_active', true)
                ->with(['project', 'roles' => fn ($query) => $query->where('is_active', true)])
                ->get()
                ->map(fn (Membership $membership) => [
                    'id' => $membership->id,
                    'project' => [
                        'id' => $membership->project->id,
                        'key' => $membership->project->key,
                        'name' => $membership->project->name,
                        'active' => $membership->project->is_active,
                    ],
                    'roles' => $membership->roles->pluck('role')->map(fn (Role $role) => $role->value)->values(),
                    'active' => $membership->is_active,
                ])
                ->values(),
        ];
    }
}
