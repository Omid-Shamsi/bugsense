<?php

namespace App\Models;

use App\Enums\ActivityEventScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only product/administrative history. Rows are created once via
 * RecordsActivity and are never updated or deleted by the application.
 */
class ActivityEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_scope',
        'project_id',
        'bug_id',
        'sequence',
        'subject_type',
        'subject_id',
        'event_type',
        'actor_id',
        'actor_display',
        'occurred_at',
        'command_id',
        'before_data',
        'after_data',
        'reason_or_result',
    ];

    protected function casts(): array
    {
        return [
            'event_scope' => ActivityEventScope::class,
            'occurred_at' => 'datetime',
            'before_data' => 'array',
            'after_data' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Enforce append-only history at the model boundary: a row may be
     * inserted once and never updated afterward.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('ActivityEvent rows are immutable and cannot be updated.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new \LogicException('ActivityEvent rows are immutable and cannot be deleted.');
    }
}
