<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Bugs\RemoveAttachment;
use App\Actions\Bugs\UploadAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Bugs\UploadAttachmentRequest;
use App\Http\Resources\Api\V1\AttachmentResource;
use App\Models\Attachment;
use App\Models\Bug;
use App\Policies\AttachmentPolicy;
use App\Queries\VisibleBugs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    /**
     * Only `ready` evidence is ever listed (data-model.md: "Only ready
     * attachments appear as evidence"), visible under the same policy as
     * the Bug itself — any current project member, not only the uploader.
     */
    public function index(Request $request, Bug $bug)
    {
        abort_unless((new VisibleBugs())->forUser($request->user())->whereKey($bug->id)->exists(), 404);

        $attachments = $bug->attachments()->where('state', 'ready')->orderBy('created_at')->get();

        return AttachmentResource::collection($attachments);
    }

    public function store(UploadAttachmentRequest $request, Bug $bug, UploadAttachment $action)
    {
        abort_unless((new VisibleBugs())->forUser($request->user())->whereKey($bug->id)->exists(), 404);
        $this->authorize('upload', [Attachment::class, $bug]);

        $attachment = $action->handle($request->user(), $bug, $request->file('file'));

        return AttachmentResource::make($attachment)->response()->setStatusCode(201);
    }

    /**
     * Live re-check on every request (FR-005): access is never trusted from
     * upload time. A removed/non-ready attachment is treated the same as an
     * inaccessible one — 404, not a distinguishing error, so its prior
     * existence is not disclosed either.
     */
    public function show(Request $request, Attachment $attachment)
    {
        abort_unless((new AttachmentPolicy())->view($request->user(), $attachment), 404);
        abort_unless($attachment->state === 'ready', 404);

        return Storage::disk('attachments')->download(
            $attachment->storage_key,
            $attachment->original_name,
            [
                'Content-Type' => $attachment->detected_content_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function destroy(Request $request, Attachment $attachment, RemoveAttachment $action)
    {
        $bug = $attachment->bug;
        abort_unless($bug !== null && (new VisibleBugs())->forUser($request->user())->whereKey($bug->id)->exists(), 404);
        $this->authorize('remove', $attachment);

        $action->handle($request->user(), $attachment);

        return response()->noContent();
    }
}
