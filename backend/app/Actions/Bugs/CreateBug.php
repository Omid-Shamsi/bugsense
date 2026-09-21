<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Models\Bug;
use App\Models\Project;
use App\Models\User;
use App\Policies\BugPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CreateBug
{
    use RecordsActivity;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Project $project, array $data): Bug
    {
        // FR-011: priority/severity may be set at creation only when the
        // Reporter also holds applicable Admin authority in this project.
        if (($data['priority_id'] ?? null) !== null || ($data['severity_id'] ?? null) !== null) {
            if (! (new BugPolicy())->setClassificationScope($actor, $project)) {
                throw new AuthorizationException('Only an Admin within scope may set priority or severity.');
            }
        }

        return DB::transaction(function () use ($actor, $project, $data) {
            $bug = Bug::create([
                'project_id' => $project->id,
                'reporter_id' => $actor->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'steps_to_reproduce' => $data['steps_to_reproduce'] ?? null,
                'expected_result' => $data['expected_result'] ?? null,
                'actual_result' => $data['actual_result'] ?? null,
                'environment' => $data['environment'] ?? null,
                'platform' => $data['platform'] ?? null,
                'application_version' => $data['application_version'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'priority_id' => $data['priority_id'] ?? null,
                'severity_id' => $data['severity_id'] ?? null,
            ]);

            // public_number is assigned by a database sequence on insert and
            // is never predictable client-side; pull it back explicitly.
            $bug->refresh();

            foreach (array_unique($data['tag_ids'] ?? []) as $tagId) {
                $bug->tags()->attach($tagId, [
                    'added_by_id' => $actor->id,
                    'added_at' => now(),
                ]);
            }

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.created',
                after: [
                    'title' => $bug->title,
                    'status' => $bug->status->value,
                ],
            );

            return $bug->load(['project', 'category', 'priority', 'severity', 'tags', 'reporter']);
        });
    }
}
