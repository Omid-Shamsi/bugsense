<?php

namespace App\Models;

use App\Enums\BugRelationshipType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BugRelationship extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'source_bug_id',
        'target_bug_id',
        'relationship_type',
        'canonical_low_bug_id',
        'canonical_high_bug_id',
        'created_by_id',
        'created_at',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'relationship_type' => BugRelationshipType::class,
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sourceBug(): BelongsTo
    {
        return $this->belongsTo(Bug::class, 'source_bug_id');
    }

    public function targetBug(): BelongsTo
    {
        return $this->belongsTo(Bug::class, 'target_bug_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by_id');
    }

    /**
     * The forward label as seen from the source Bug's side (FR-033/US8.2).
     */
    public function forwardLabel(): string
    {
        return match ($this->relationship_type) {
            BugRelationshipType::DuplicateOf => 'Duplicate of',
            BugRelationshipType::RelatedTo => 'Related to',
            BugRelationshipType::Blocks => 'Blocks',
        };
    }

    /**
     * The reverse label as seen from the target Bug's side. Related-to is
     * symmetric, so its reverse label is identical to its forward label.
     */
    public function reverseLabel(): string
    {
        return match ($this->relationship_type) {
            BugRelationshipType::DuplicateOf => 'Has duplicates',
            BugRelationshipType::RelatedTo => 'Related to',
            BugRelationshipType::Blocks => 'Blocked by',
        };
    }
}
