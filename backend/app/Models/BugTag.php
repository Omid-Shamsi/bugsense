<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BugTag extends Pivot
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'bug_tags';

    protected $fillable = [
        'bug_id',
        'tracking_value_id',
        'added_by_id',
        'added_at',
    ];

    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
        ];
    }

    public function bug(): BelongsTo
    {
        return $this->belongsTo(Bug::class);
    }

    public function trackingValue(): BelongsTo
    {
        return $this->belongsTo(TrackingValue::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }
}
