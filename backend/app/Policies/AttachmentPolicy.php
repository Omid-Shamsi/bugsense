<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Bug;
use App\Models\User;
use App\Queries\VisibleBugs;

class AttachmentPolicy
{
    /**
     * Download authorization is evaluated live against the underlying Bug's
     * CURRENT visibility every time — never snapshotted at upload time. A
     * membership/role/project change since upload takes effect immediately
     * (FR-005).
     */
    public function view(User $actor, Attachment $attachment): bool
    {
        return (new VisibleBugs())->forUser($actor)->whereKey($attachment->bug_id)->exists();
    }

    /**
     * Upload authority is exactly the existing report-edit window — reused
     * from BugPolicy::update, never re-derived here.
     */
    public function upload(User $actor, Bug $bug): bool
    {
        return (new BugPolicy())->update($actor, $bug);
    }

    public function remove(User $actor, Attachment $attachment): bool
    {
        $bug = $attachment->bug;

        return $bug !== null && (new BugPolicy())->update($actor, $bug);
    }
}
