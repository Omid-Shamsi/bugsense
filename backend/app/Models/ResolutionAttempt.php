<?php

namespace App\Models;

use App\Enums\ResolutionOutcome;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One immutable attempted disposition. Prior attempts survive reopening
 * (Phase 8); never overwritten, never renumbered.
 */
class ResolutionAttempt extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'bug_id',
        'attempt_number',
        'outcome',
        'recorded_by_id',
        'source_status',
        'explanation',
        'qa_instructions',
        'duplicate_relationship_id',
        'reproduction_attempts',
        'reproduction_environment',
        'decision_rationale',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => ResolutionOutcome::class,
            'attempt_number' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function bug(): BelongsTo
    {
        return $this->belongsTo(Bug::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    public function duplicateRelationship(): BelongsTo
    {
        return $this->belongsTo(BugRelationship::class, 'duplicate_relationship_id');
    }

    public function qaVerificationResult(): HasOne
    {
        return $this->hasOne(QAVerificationResult::class);
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('ResolutionAttempt rows are immutable and cannot be updated.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new \LogicException('ResolutionAttempt rows are immutable and cannot be deleted.');
    }
}
