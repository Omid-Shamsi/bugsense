<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence metadata only — file bytes live on the private disk under
 * `storage_key`, never under a name derived from user input. Deliberately a
 * two-state lifecycle (ready/removed): there is no staged/processing
 * pipeline, so a row is never persisted until upload has fully succeeded.
 */
class Attachment extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'bug_id',
        'uploaded_by_id',
        'storage_key',
        'original_name',
        'declared_content_type',
        'detected_content_type',
        'byte_size',
        'sha256',
        'created_at',
    ];

    protected $attributes = [
        'state' => 'ready',
    ];

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'created_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function bug(): BelongsTo
    {
        return $this->belongsTo(Bug::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by_id');
    }

    public function isReady(): bool
    {
        return $this->state === 'ready';
    }
}
