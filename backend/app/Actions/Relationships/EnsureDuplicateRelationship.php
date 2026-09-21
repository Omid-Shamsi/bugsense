<?php

namespace App\Actions\Relationships;

use App\Enums\BugRelationshipType;
use App\Models\Bug;
use App\Models\BugRelationship;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Creates or reuses the minimal active same-project directional duplicate_of
 * link required by a Duplicate resolution (T026/T037). Must run inside the
 * caller's own Bug-locked transaction so the relationship, the
 * ResolutionAttempt, and the Bug transition succeed or roll back together.
 */
class EnsureDuplicateRelationship
{
    public function handle(User $actor, Bug $source, Bug $target): BugRelationship
    {
        if ($source->id === $target->id) {
            throw ValidationException::withMessages([
                'duplicate_bug_id' => 'A bug cannot be a duplicate of itself.',
            ]);
        }

        if ($source->project_id !== $target->project_id) {
            throw ValidationException::withMessages([
                'duplicate_bug_id' => 'The original bug must be in the same project.',
            ]);
        }

        $existing = BugRelationship::where('relationship_type', BugRelationshipType::DuplicateOf->value)
            ->where('source_bug_id', $source->id)
            ->where('target_bug_id', $target->id)
            ->where('is_active', true)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($this->wouldCreateCycle($source->id, $target->id)) {
            throw new ConflictHttpException('This duplicate link would create a cycle with existing duplicate relationships.');
        }

        return BugRelationship::create([
            'project_id' => $source->project_id,
            'source_bug_id' => $source->id,
            'target_bug_id' => $target->id,
            'relationship_type' => BugRelationshipType::DuplicateOf,
            'created_by_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    /**
     * A cycle would form if the proposed original ($targetId) can already
     * reach the proposed duplicate ($sourceId) through existing active
     * duplicate_of edges. A straightforward recursive traversal is
     * sufficient at this project's scale; no graph infrastructure needed.
     */
    private function wouldCreateCycle(string $sourceId, string $targetId): bool
    {
        $row = DB::selectOne(
            <<<'SQL'
            WITH RECURSIVE reachable(node) AS (
                SELECT target_bug_id FROM bug_relationships
                    WHERE relationship_type = 'duplicate_of' AND is_active AND source_bug_id = ?
                UNION
                SELECT br.target_bug_id FROM bug_relationships br
                    INNER JOIN reachable r ON br.source_bug_id = r.node
                    WHERE br.relationship_type = 'duplicate_of' AND br.is_active
            )
            SELECT 1 AS hit FROM reachable WHERE node = ? LIMIT 1
            SQL,
            [$targetId, $sourceId],
        );

        return $row !== null;
    }
}
