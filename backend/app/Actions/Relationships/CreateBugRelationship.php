<?php

namespace App\Actions\Relationships;

use App\Actions\Concerns\RecordsActivity;
use App\Enums\BugRelationshipType;
use App\Models\Bug;
use App\Models\BugRelationship;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * General Admin relationship management (FR-033/FR-034): duplicate_of
 * (delegates to the existing EnsureDuplicateRelationship so cycle logic is
 * never duplicated), related_to (symmetric, canonical pair), and blocks
 * (directional, informational only — never gates a workflow transition).
 */
class CreateBugRelationship
{
    use RecordsActivity;

    public function handle(User $actor, Bug $source, BugRelationshipType $type, Bug $target): BugRelationship
    {
        return DB::transaction(function () use ($actor, $source, $type, $target) {
            $relationship = match ($type) {
                BugRelationshipType::DuplicateOf => app(EnsureDuplicateRelationship::class)->handle($actor, $source, $target),
                BugRelationshipType::RelatedTo => $this->createRelatedTo($actor, $source, $target),
                BugRelationshipType::Blocks => $this->createDirectional($actor, $source, $target, BugRelationshipType::Blocks),
            };

            $this->recordBugEvent(
                bug: $source,
                actor: $actor,
                eventType: 'bug.relationship_created',
                after: [
                    'relationship_id' => $relationship->id,
                    'relationship_type' => $type->value,
                    'target_bug_id' => $target->id,
                ],
            );

            return $relationship;
        });
    }

    private function assertSameProjectNoSelfLink(Bug $source, Bug $target): void
    {
        if ($source->id === $target->id) {
            throw ValidationException::withMessages([
                'target_bug_id' => 'A bug cannot be related to itself.',
            ]);
        }

        if ($source->project_id !== $target->project_id) {
            throw ValidationException::withMessages([
                'target_bug_id' => 'The target bug must be in the same project.',
            ]);
        }
    }

    private function createRelatedTo(User $actor, Bug $source, Bug $target): BugRelationship
    {
        $this->assertSameProjectNoSelfLink($source, $target);

        $low = min($source->id, $target->id);
        $high = max($source->id, $target->id);

        $existing = BugRelationship::where('relationship_type', BugRelationshipType::RelatedTo->value)
            ->where('canonical_low_bug_id', $low)
            ->where('canonical_high_bug_id', $high)
            ->where('is_active', true)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return BugRelationship::create([
            'project_id' => $source->project_id,
            'source_bug_id' => $source->id,
            'target_bug_id' => $target->id,
            'relationship_type' => BugRelationshipType::RelatedTo,
            'canonical_low_bug_id' => $low,
            'canonical_high_bug_id' => $high,
            'created_by_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    private function createDirectional(User $actor, Bug $source, Bug $target, BugRelationshipType $type): BugRelationship
    {
        $this->assertSameProjectNoSelfLink($source, $target);

        $existing = BugRelationship::where('relationship_type', $type->value)
            ->where('source_bug_id', $source->id)
            ->where('target_bug_id', $target->id)
            ->where('is_active', true)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return BugRelationship::create([
            'project_id' => $source->project_id,
            'source_bug_id' => $source->id,
            'target_bug_id' => $target->id,
            'relationship_type' => $type,
            'created_by_id' => $actor->id,
            'created_at' => now(),
        ]);
    }
}
