<?php

namespace App\Actions\Relationships;

use App\Actions\Concerns\RecordsActivity;
use App\Models\BugRelationship;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Non-destructive relationship removal (FR-033/FR-034): the row, its type,
 * endpoints, and creation attribution are retained; only is_active flips.
 */
class DeactivateBugRelationship
{
    use RecordsActivity;

    public function handle(User $actor, BugRelationship $relationship): BugRelationship
    {
        return DB::transaction(function () use ($actor, $relationship) {
            /** @var BugRelationship $relationship */
            $relationship = BugRelationship::whereKey($relationship->id)->lockForUpdate()->firstOrFail();

            if (! $relationship->is_active) {
                throw new ConflictHttpException('This relationship is already inactive.');
            }

            $relationship->is_active = false;
            $relationship->deactivated_at = now();
            $relationship->deactivated_by_id = $actor->id;
            $relationship->save();

            $this->recordBugEvent(
                bug: $relationship->sourceBug,
                actor: $actor,
                eventType: 'bug.relationship_deactivated',
                after: [
                    'relationship_id' => $relationship->id,
                    'relationship_type' => $relationship->relationship_type->value,
                    'target_bug_id' => $relationship->target_bug_id,
                ],
            );

            return $relationship;
        });
    }
}
