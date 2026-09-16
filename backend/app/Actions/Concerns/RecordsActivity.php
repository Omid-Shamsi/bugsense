<?php

namespace App\Actions\Concerns;

use App\Enums\ActivityEventScope;
use App\Models\ActivityEvent;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Shared, append-only activity recording for Actions. Every protected
 * administrative change writes its ActivityEvent row in the same database
 * transaction as the change it describes (see the calling Action), so a
 * failed insert here rolls back the whole command.
 */
trait RecordsActivity
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    protected function recordAdministrativeEvent(
        ?User $actor,
        string $subjectType,
        string $subjectId,
        string $eventType,
        array $before = [],
        array $after = [],
        ?string $reasonOrResult = null,
        ?string $projectId = null,
        ?string $commandId = null,
    ): ActivityEvent {
        return ActivityEvent::create([
            'event_scope' => ActivityEventScope::Administration,
            'project_id' => $projectId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event_type' => $eventType,
            'actor_id' => $actor?->id,
            'actor_display' => $actor?->display_name ?? 'system',
            'occurred_at' => now(),
            'command_id' => $commandId ?? (string) Str::uuid(),
            'before_data' => $before,
            'after_data' => $after,
            'reason_or_result' => $reasonOrResult,
        ]);
    }
}
