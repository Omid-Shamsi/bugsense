<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Membership extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['project_id', 'user_id', 'joined_at'];

    /**
     * Eloquent does not read back column DEFAULTs after INSERT, so a
     * just-created in-memory instance would otherwise see is_active as
     * null until refreshed. Mirror the migration's default explicitly.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'joined_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(MembershipRole::class);
    }

    public function activeRoles(): HasMany
    {
        return $this->roles()->where('is_active', true);
    }

    public function hasActiveRole(Role $role): bool
    {
        return $this->is_active && $this->activeRoles()->where('role', $role->value)->exists();
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by_id');
    }
}
