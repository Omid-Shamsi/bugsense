<?php

use App\Actions\Bugs\RemoveAttachment;
use App\Actions\Bugs\UploadAttachment;
use App\Enums\Role;
use App\Models\ActivityEvent;
use App\Models\Attachment;
use App\Models\Bug;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

function paReporter(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Reporter)->create();

    return $user;
}

function paAdmin(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Admin)->create();

    return $user;
}

function paDeveloper(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Developer)->create();

    return $user;
}

function paBug(Project $project, User $reporter, array $attributes = []): Bug
{
    return Bug::factory()->for($project)->for($reporter, 'reporter')->create($attributes);
}

/**
 * Real content-backed uploads only — Laravel's UploadedFile::fake() stub
 * overrides getMimeType() to guess from the extension (or an explicit
 * override), which never exercises real finfo content detection. A
 * genuine UploadedFile pointed at real bytes is the only way to prove the
 * server defeats a spoofed extension/declared type.
 */
function paFile(string $content, string $name, string $declaredMime): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'att');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, $declaredMime, null, true);
}

function paPngBytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
}

function paJpegBytes(): string
{
    return base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k=');
}

function paWebpBytes(): string
{
    return base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAQAcJaQAA3AA/v3AgAA=');
}

function paPdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
}

function paTextBytes(): string
{
    return "Reproduction log:\nStep 1 failed.\n";
}

function paExeBytes(): string
{
    return "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00This program cannot be run in DOS mode.";
}

beforeEach(function () {
    Storage::fake('attachments');
});

// -- Authorization ------------------------------------------------------------------

it('lets a permitted Reporter upload an allowed file to their own editable Bug', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);

    $response = $this->actingAs($reporter)->post("/api/v1/bugs/{$bug->public_id}/attachments", [
        'file' => paFile(paPngBytes(), 'evidence.png', 'image/png'),
    ]);

    $response->assertCreated();
    expect($response->json('data.state'))->toBe('ready');
});

it('lets a Reporter upload while the Bug is Needs Information', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'needs_information']);

    $response = $this->actingAs($reporter)->post("/api/v1/bugs/{$bug->public_id}/attachments", [
        'file' => paFile(paPngBytes(), 'evidence.png', 'image/png'),
    ]);

    $response->assertCreated();
});

it('denies upload from a project member without report-edit authority', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $developer = paDeveloper($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);

    $response = $this->actingAs($developer)->post("/api/v1/bugs/{$bug->public_id}/attachments", [
        'file' => paFile(paPngBytes(), 'evidence.png', 'image/png'),
    ]);

    $response->assertStatus(403);
    expect(Attachment::count())->toBe(0);
});

it('non-discloses upload to an inaccessible Bug', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $outsider = paReporter($projectB);
    $bug = paBug($projectA, paReporter($projectA), ['status' => 'submitted']);

    $response = $this->actingAs($outsider)->post("/api/v1/bugs/{$bug->public_id}/attachments", [
        'file' => paFile(paPngBytes(), 'evidence.png', 'image/png'),
    ]);

    $response->assertStatus(404);
});

it('denies upload once the report edit window has closed', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'review']);

    $response = $this->actingAs($reporter)->post("/api/v1/bugs/{$bug->public_id}/attachments", [
        'file' => paFile(paPngBytes(), 'evidence.png', 'image/png'),
    ]);

    $response->assertStatus(403);
});

it('lets any current project member download ready evidence, not only the uploader', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $developer = paDeveloper($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);
    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), 'evidence.png', 'image/png'));

    $response = $this->actingAs($developer)->get("/api/v1/attachments/{$attachment->id}");

    $response->assertOk();
});

it('immediately blocks download once the downloader loses project access', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $developer = paDeveloper($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);
    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), 'evidence.png', 'image/png'));

    $this->actingAs($developer)->get("/api/v1/attachments/{$attachment->id}")->assertOk();

    $membership = Membership::where('user_id', $developer->id)->where('project_id', $project->id)->first();
    $membership->is_active = false;
    $membership->deactivated_at = now();
    $membership->save();

    $response = $this->actingAs($developer)->get("/api/v1/attachments/{$attachment->id}");
    $response->assertStatus(404);
});

it('immediately blocks download once the deactivated developer role no longer grants project access', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $developer = paDeveloper($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);
    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), 'evidence.png', 'image/png'));

    $this->actingAs($developer)->get("/api/v1/attachments/{$attachment->id}")->assertOk();

    // Deactivating the project entirely also removes every member's live
    // visibility (VisibleProjects), independent of any individual
    // membership row — a second, distinct path to the same access loss.
    $project->is_active = false;
    $project->save();

    $response = $this->actingAs($developer)->get("/api/v1/attachments/{$attachment->id}");
    $response->assertStatus(404);
});

// -- Type validation ------------------------------------------------------------------

it('accepts PNG, JPEG, WebP, PDF, and plain text evidence', function (string $content, string $name, string $declared) {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);

    $response = $this->actingAs($reporter)->post("/api/v1/bugs/{$bug->public_id}/attachments", [
        'file' => paFile($content, $name, $declared),
    ]);

    $response->assertCreated();
})->with([
    'png' => [paPngBytes(), 'shot.png', 'image/png'],
    'jpeg' => [paJpegBytes(), 'shot.jpg', 'image/jpeg'],
    'webp' => [paWebpBytes(), 'shot.webp', 'image/webp'],
    'pdf' => [paPdfBytes(), 'report.pdf', 'application/pdf'],
    'text' => [paTextBytes(), 'notes.txt', 'text/plain'],
]);

it('accepts a .log file, detected the same way as plain text', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);

    $response = $this->actingAs($reporter)->post("/api/v1/bugs/{$bug->public_id}/attachments", [
        'file' => paFile(paTextBytes(), 'server.log', 'text/plain'),
    ]);

    $response->assertCreated();
    expect($response->json('data.detected_content_type'))->toBe('text/plain');
});

it('rejects an unsupported file type as 415', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);

    $response = $this->actingAs($reporter)->post("/api/v1/bugs/{$bug->public_id}/attachments", [
        'file' => paFile(paExeBytes(), 'tool.exe', 'application/x-msdownload'),
    ]);

    $response->assertStatus(415);
    expect(Attachment::count())->toBe(0);
});

it('rejects a spoofed extension/declared type using real server-side detection', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);

    $response = $this->actingAs($reporter)->post("/api/v1/bugs/{$bug->public_id}/attachments", [
        'file' => paFile(paExeBytes(), 'totally-a-photo.png', 'image/png'),
    ]);

    $response->assertStatus(415);
    expect(Attachment::count())->toBe(0);
});

// -- Size validation ------------------------------------------------------------------

it('rejects a zero-byte file as 422', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);

    $response = $this->actingAs($reporter)->post("/api/v1/bugs/{$bug->public_id}/attachments", [
        'file' => paFile('', 'empty.txt', 'text/plain'),
    ]);

    $response->assertStatus(422);
    expect(Attachment::count())->toBe(0);
});

it('accepts a file at exactly the configured maximum size', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);
    $maxBytes = (int) config('attachments.max_bytes');

    $response = $this->actingAs($reporter)->post("/api/v1/bugs/{$bug->public_id}/attachments", [
        'file' => paFile(str_repeat('a', $maxBytes), 'exact.txt', 'text/plain'),
    ]);

    $response->assertCreated();
    expect($response->json('data.byte_size'))->toBe($maxBytes);
});

it('rejects an oversized file as 413', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);
    $maxBytes = (int) config('attachments.max_bytes');

    $response = $this->actingAs($reporter)->post("/api/v1/bugs/{$bug->public_id}/attachments", [
        'file' => paFile(str_repeat('a', $maxBytes + 1), 'toobig.txt', 'text/plain'),
    ]);

    $response->assertStatus(413);
    expect(Attachment::count())->toBe(0);
});

// -- Persistence --------------------------------------------------------------------

it('persists an opaque unique storage key unrelated to the original filename', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);

    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), 'my-screenshot.png', 'image/png'));

    expect($attachment->storage_key)->not->toBe('my-screenshot.png');
    expect(preg_match('/^[0-9a-f-]{36}$/i', $attachment->storage_key))->toBe(1);
    Storage::disk('attachments')->assertExists($attachment->storage_key);
});

it('sanitizes a path-traversal filename to a bare presentation name', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);

    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), '../../etc/passwd.png', 'image/png'));

    expect($attachment->original_name)->toBe('passwd.png');
    expect($attachment->original_name)->not->toContain('/');
    expect($attachment->original_name)->not->toContain('..');
});

it('persists both declared and detected content types, and a positive byte size', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);

    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), 'evidence.png', 'image/png'));

    expect($attachment->declared_content_type)->toBe('image/png');
    expect($attachment->detected_content_type)->toBe('image/png');
    expect($attachment->byte_size)->toBeGreaterThan(0);
    expect($attachment->state)->toBe('ready');
});

// -- Privacy ------------------------------------------------------------------------

it('never exposes the storage key or a filesystem path in the API response', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);

    $response = $this->actingAs($reporter)->post("/api/v1/bugs/{$bug->public_id}/attachments", [
        'file' => paFile(paPngBytes(), 'evidence.png', 'image/png'),
    ]);

    $raw = $response->getContent();
    expect($raw)->not->toContain('storage_key');
    expect($raw)->not->toContain('storage/app');
    expect($raw)->not->toContain(storage_path());
});

it('never exposes a public file URL for evidence', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);
    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), 'evidence.png', 'image/png'));

    $response = $this->get("/storage/attachments/{$attachment->storage_key}");

    $response->assertStatus(404);
});

// -- Download -----------------------------------------------------------------------

it('streams the exact uploaded bytes with an appropriate filename and content type', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);
    $pngBytes = paPngBytes();
    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile($pngBytes, 'evidence.png', 'image/png'));

    $response = $this->actingAs($reporter)->get("/api/v1/attachments/{$attachment->id}");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/png');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toContain('evidence.png');

    expect($response->streamedContent())->toBe($pngBytes);
});

it('makes a removed attachment unavailable for download', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);
    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), 'evidence.png', 'image/png'));
    (new RemoveAttachment())->handle($reporter, $attachment);

    $response = $this->actingAs($reporter)->get("/api/v1/attachments/{$attachment->id}");

    $response->assertStatus(404);
});

it('excludes a removed attachment from the Bug attachment list', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);
    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), 'evidence.png', 'image/png'));
    (new RemoveAttachment())->handle($reporter, $attachment);

    $response = $this->actingAs($reporter)->getJson("/api/v1/bugs/{$bug->public_id}/attachments");

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

// -- Removal ------------------------------------------------------------------------

it('removes an attachment non-destructively, preserving the row, metadata, and its ActivityEvent', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);
    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), 'evidence.png', 'image/png'));

    $response = $this->actingAs($reporter)->deleteJson("/api/v1/attachments/{$attachment->id}");

    $response->assertNoContent();

    $fresh = Attachment::findOrFail($attachment->id);
    expect($fresh->state)->toBe('removed');
    expect($fresh->removed_at)->not->toBeNull();
    expect($fresh->removed_by_id)->toBe($reporter->id);
    expect($fresh->original_name)->toBe('evidence.png');

    $event = ActivityEvent::where('bug_id', $bug->id)->where('event_type', 'bug.attachment_removed')->first();
    expect($event)->not->toBeNull();
});

it('rejects a repeated removal as a conflict', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);
    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), 'evidence.png', 'image/png'));
    (new RemoveAttachment())->handle($reporter, $attachment);

    $response = $this->actingAs($reporter)->deleteJson("/api/v1/attachments/{$attachment->id}");

    $response->assertStatus(409);
});

it('denies removal from a project member without report-edit authority', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $developer = paDeveloper($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);
    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), 'evidence.png', 'image/png'));

    $response = $this->actingAs($developer)->deleteJson("/api/v1/attachments/{$attachment->id}");

    $response->assertStatus(403);
    expect($attachment->fresh()->state)->toBe('ready');
});

// -- Rollback / atomicity -------------------------------------------------------------

it('cleans up the orphaned physical file when the required ActivityEvent cannot be written', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);

    $action = new class extends UploadAttachment
    {
        protected function recordBugEvent(...$args): ActivityEvent
        {
            throw new RuntimeException('simulated activity-event failure');
        }
    };

    expect(fn () => $action->handle($reporter, $bug, paFile(paPngBytes(), 'evidence.png', 'image/png')))
        ->toThrow(RuntimeException::class);

    expect(Attachment::count())->toBe(0);

    $stored = Storage::disk('attachments')->allFiles();
    expect($stored)->toBe([]);
});

it('creates no ready row when the physical file cannot be stored', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);

    // UploadedFile::storeAs() resolves the filesystem factory straight from
    // the container (bypassing the Storage facade's own disk cache), so
    // mocking the facade's 'disk' call reaches it too — this exercises the
    // regression guard added to UploadAttachment: storeAs()'s return value
    // must be checked, not ignored, or a "ready" row could point at bytes
    // that were never actually written.
    $brokenDisk = Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
    $brokenDisk->shouldReceive('putFileAs')->andReturn(false);
    Storage::shouldReceive('disk')->with('attachments')->andReturn($brokenDisk);

    expect(fn () => (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), 'evidence.png', 'image/png')))
        ->toThrow(HttpException::class);

    expect(Attachment::count())->toBe(0);
});

it('leaves an attachment untouched when removal fails after its lock is acquired', function () {
    $project = Project::factory()->create();
    $reporter = paReporter($project);
    $bug = paBug($project, $reporter, ['status' => 'submitted']);
    $attachment = (new UploadAttachment())->handle($reporter, $bug, paFile(paPngBytes(), 'evidence.png', 'image/png'));

    $action = new class extends RemoveAttachment
    {
        protected function recordBugEvent(...$args): ActivityEvent
        {
            throw new RuntimeException('simulated activity-event failure');
        }
    };

    expect(fn () => $action->handle($reporter, $attachment))->toThrow(RuntimeException::class);

    $fresh = $attachment->fresh();
    expect($fresh->state)->toBe('ready');
    expect($fresh->removed_at)->toBeNull();
});
