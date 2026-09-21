<?php

namespace App\Actions\Bugs;

use App\Actions\Concerns\RecordsActivity;
use App\Models\Attachment;
use App\Models\Bug;
use App\Models\User;
use App\Policies\BugPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * Direct-to-private-disk evidence upload (FR-012/FR-013). Upload authority
 * is exactly the existing report-edit window — the Reporter's own Bug while
 * Submitted/Needs Information, or an Admin within scope at any status —
 * reused from BugPolicy::update rather than re-implemented here. No staged
 * scan/processing pipeline: a row only ever exists once every check has
 * already passed, so "ready" is the only state a persisted row can start in.
 */
class UploadAttachment
{
    use RecordsActivity;

    public function handle(User $actor, Bug $bug, UploadedFile $file): Attachment
    {
        // Cheap pre-flight so an unauthorized/stale-window request never
        // pays for hashing/MIME-sniffing or a disk write.
        $this->assertEditable($actor, $bug->fresh());

        $originalName = $this->sanitizeFilename($file->getClientOriginalName());
        $byteSize = (int) $file->getSize();
        // Detected via Symfony's content-based MIME guesser (ext-fileinfo),
        // not the client-supplied header — declared type is diagnostics only.
        $declaredType = (string) $file->getClientMimeType();
        $detectedType = (string) $file->getMimeType();

        if ($byteSize <= 0) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file is empty or unreadable.',
            ]);
        }

        $maxBytes = (int) config('attachments.max_bytes');

        if ($byteSize > $maxBytes) {
            throw new HttpException(413, 'The uploaded file exceeds the maximum allowed size.');
        }

        $allowed = config('attachments.allowed_mime_types');

        if (! in_array($detectedType, $allowed, true)) {
            throw new UnsupportedMediaTypeHttpException('This file type is not supported as evidence.');
        }

        $sha256 = hash_file('sha256', $file->getRealPath());
        $storageKey = (string) Str::uuid();

        $stored = $file->storeAs('', $storageKey, ['disk' => 'attachments']);

        if ($stored === false) {
            throw new HttpException(500, 'Failed to store the uploaded file.');
        }

        try {
            return DB::transaction(function () use ($actor, $bug, $storageKey, $originalName, $declaredType, $detectedType, $byteSize, $sha256) {
                /** @var Bug $lockedBug */
                $lockedBug = Bug::whereKey($bug->id)->lockForUpdate()->firstOrFail();

                // Re-checked under lock: the edit window is a live invariant,
                // not something trusted from the pre-flight read above.
                $this->assertEditable($actor, $lockedBug);

                $attachment = Attachment::create([
                    'bug_id' => $lockedBug->id,
                    'uploaded_by_id' => $actor->id,
                    'storage_key' => $storageKey,
                    'original_name' => $originalName,
                    'declared_content_type' => $declaredType,
                    'detected_content_type' => $detectedType,
                    'byte_size' => $byteSize,
                    'sha256' => $sha256,
                    'created_at' => now(),
                ]);

                $this->recordBugEvent(
                    bug: $lockedBug,
                    actor: $actor,
                    eventType: 'bug.attachment_uploaded',
                    after: [
                        'attachment_id' => $attachment->id,
                        'original_name' => $attachment->original_name,
                        'detected_content_type' => $attachment->detected_content_type,
                        'byte_size' => $attachment->byte_size,
                    ],
                );

                return $attachment;
            });
        } catch (\Throwable $e) {
            // The physical write above happened outside this transaction —
            // if anything after it failed, no DB row survives, so the file
            // must not survive either.
            Storage::disk('attachments')->delete($storageKey);

            throw $e;
        }
    }

    private function assertEditable(User $actor, Bug $bug): void
    {
        if (! (new BugPolicy())->update($actor, $bug)) {
            throw new AuthorizationException('This report is not currently editable.');
        }
    }

    /**
     * Strips any directory component, control characters, and stray
     * leading/trailing dots/whitespace. Never trusted as a filesystem path
     * — presentation/download metadata only.
     */
    private function sanitizeFilename(?string $name): string
    {
        $name = str_replace('\\', '/', (string) $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        $name = trim($name, " .\t\n\r\0\x0B");
        $name = mb_substr($name, 0, 255);

        return $name !== '' ? $name : 'attachment';
    }
}
