<?php

namespace App\Models;

use App\Enums\QAVerificationDecision;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable pass/fail decision per ResolutionAttempt. Approval closes
 * the Bug; rejection reopens it. Never overwritten — a later attempt gets
 * its own separate result row.
 */
class QAVerificationResult extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'qa_verification_results';

    protected $fillable = [
        'resolution_attempt_id',
        'verifier_id',
        'decision',
        'verification_notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'decision' => QAVerificationDecision::class,
            'created_at' => 'datetime',
        ];
    }

    public function resolutionAttempt(): BelongsTo
    {
        return $this->belongsTo(ResolutionAttempt::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifier_id');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('QAVerificationResult rows are immutable and cannot be updated.');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new \LogicException('QAVerificationResult rows are immutable and cannot be deleted.');
    }
}
