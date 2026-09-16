<?php

namespace App\Models;

use App\Enums\TrackingValueKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackingValue extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['project_id', 'kind', 'code', 'name', 'rank'];

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
            'kind' => TrackingValueKind::class,
            'rank' => 'integer',
            'is_active' => 'boolean',
            'deactivated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by_id');
    }
}
