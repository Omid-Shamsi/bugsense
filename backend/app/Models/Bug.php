<?php

namespace App\Models;

use App\Enums\BugStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Bug extends Model
{
    use HasFactory, HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'project_id',
        'reporter_id',
        'title',
        'description',
        'steps_to_reproduce',
        'expected_result',
        'actual_result',
        'environment',
        'platform',
        'application_version',
        'category_id',
        'priority_id',
        'severity_id',
    ];

    /**
     * Eloquent does not read back column DEFAULTs after INSERT. `status`
     * mirrors the migration's default explicitly; `public_number` cannot be
     * predicted client-side (it comes from a database sequence), so
     * CreateBug refreshes the model after insert instead.
     */
    protected $attributes = [
        'status' => 'submitted',
    ];

    protected function casts(): array
    {
        return [
            'status' => BugStatus::class,
        ];
    }

    /**
     * Render the stable, sequence-backed public_number as the human-facing
     * key (e.g. BUG-000123). The number never changes once assigned.
     */
    public function getPublicIdAttribute(): string
    {
        return sprintf('BUG-%06d', $this->public_number);
    }

    /**
     * Route-model-bind on the rendered public key (e.g. "BUG-000123")
     * instead of the UUID primary key.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if (! preg_match('/^BUG-(\d+)$/', (string) $value, $matches)) {
            return null;
        }

        return $this->where('public_number', (int) $matches[1])->first();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assigneeMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'assignee_membership_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TrackingValue::class, 'category_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(TrackingValue::class, 'priority_id');
    }

    public function severity(): BelongsTo
    {
        return $this->belongsTo(TrackingValue::class, 'severity_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(TrackingValue::class, 'bug_tags')
            ->using(BugTag::class)
            ->withPivot(['added_by_id', 'added_at']);
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(ActivityEvent::class)->orderBy('sequence');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function informationRequests(): HasMany
    {
        return $this->hasMany(InformationRequest::class);
    }

    public function openInformationRequest(): HasOne
    {
        return $this->hasOne(InformationRequest::class)->whereNull('responded_at');
    }

    public function progressUpdates(): HasMany
    {
        return $this->hasMany(ProgressUpdate::class)->orderBy('created_at');
    }

    public function resolutionAttempts(): HasMany
    {
        return $this->hasMany(ResolutionAttempt::class)->orderBy('attempt_number');
    }

    public function activeResolutionAttempt(): BelongsTo
    {
        return $this->belongsTo(ResolutionAttempt::class, 'active_resolution_attempt_id');
    }

    /**
     * FR-042: open means any status except Closed.
     */
    public function isOpen(): bool
    {
        return $this->status !== BugStatus::Closed;
    }

    public function scopeOpen($query)
    {
        return $query->where('status', '!=', BugStatus::Closed->value);
    }
}
