<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Models\Bug;
use App\Models\User;
use App\Policies\BugPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class UpdateBug
{
    use RecordsActivity;

    private const EDITABLE_FIELDS = [
        'title',
        'description',
        'steps_to_reproduce',
        'expected_result',
        'actual_result',
        'environment',
        'platform',
        'application_version',
        'category_id',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(User $actor, Bug $bug, array $data): Bug
    {
        if (array_key_exists('priority_id', $data) || array_key_exists('severity_id', $data)) {
            if (! (new BugPolicy())->setClassificationScope($actor, $bug->project)) {
                throw new AuthorizationException('Only an Admin within scope may set priority or severity.');
            }
        }

        return DB::transaction(function () use ($actor, $bug, $data) {
            /** @var Bug $bug */
            $bug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

            $before = $bug->only([...self::EDITABLE_FIELDS, 'priority_id', 'severity_id']);
            $before['tag_ids'] = $bug->tags()->pluck('tracking_values.id')->sort()->values()->all();

            foreach (self::EDITABLE_FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $bug->{$field} = $data[$field];
                }
            }

            if (array_key_exists('priority_id', $data)) {
                $bug->priority_id = $data['priority_id'];
            }
            if (array_key_exists('severity_id', $data)) {
                $bug->severity_id = $data['severity_id'];
            }

            $bug->save();

            if (array_key_exists('tag_ids', $data)) {
                $bug->tags()->sync(collect(array_unique($data['tag_ids']))->mapWithKeys(fn ($tagId) => [
                    $tagId => ['added_by_id' => $actor->id, 'added_at' => now()],
                ]));
            }

            $after = $bug->only([...self::EDITABLE_FIELDS, 'priority_id', 'severity_id']);
            $after['tag_ids'] = $bug->tags()->pluck('tracking_values.id')->sort()->values()->all();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.updated',
                before: $before,
                after: $after,
            );

            return $bug->load(['project', 'category', 'priority', 'severity', 'tags', 'reporter']);
        });
    }
}
