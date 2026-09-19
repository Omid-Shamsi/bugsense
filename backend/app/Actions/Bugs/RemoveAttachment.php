<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Models\Attachment;
use App\Models\Bug;
use App\Models\User;
use App\Policies\BugPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Non-destructive removal (data-model.md: "Removal hides bytes from
 * ordinary use but preserves metadata and the related ActivityEvent").
 * Never hard-deletes the row, and never deletes the physical file here —
 * that is a separate, unimplemented retention-period cleanup concern, not
 * part of this synchronous command. Same edit-window authority as upload.
 */
class RemoveAttachment
{
    use RecordsActivity;

    public function handle(User $actor, Attachment $attachment): Attachment
    {
        return DB::transaction(function () use ($actor, $attachment) {
            /** @var Attachment $locked */
            $locked = Attachment::whereKey($attachment->id)->lockForUpdate()->firstOrFail();

            if ($locked->state === 'removed') {
                throw new ConflictHttpException('This attachment has already been removed.');
            }

            /** @var Bug $bug */
            $bug = Bug::whereKey($locked->bug_id)->lockForUpdate()->firstOrFail();

            if (! (new BugPolicy())->update($actor, $bug)) {
                throw new AuthorizationException('This report is not currently editable.');
            }

            $locked->state = 'removed';
            $locked->removed_at = now();
            $locked->removed_by_id = $actor->id;
            $locked->save();

            $this->recordBugEvent(
                bug: $bug,
                actor: $actor,
                eventType: 'bug.attachment_removed',
                after: [
                    'attachment_id' => $locked->id,
                    'original_name' => $locked->original_name,
                ],
            );

            return $locked;
        });
    }
}
