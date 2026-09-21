<?php

use App\Actions\Bugs\AssignDeveloper;
use App\Actions\Relationships\CreateBugRelationship;
use App\Actions\Relationships\DeactivateBugRelationship;
use App\Enums\BugRelationshipType;
use App\Enums\Role;
use App\Models\ActivityEvent;
use App\Models\Bug;
use App\Models\BugRelationship;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;

function arReporter(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Reporter)->create();

    return $user;
}

function arAdmin(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Admin)->create();

    return $user;
}

function arDeveloper(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Developer)->create();

    return $user;
}

function arBug(Project $project, ?User $reporter = null): Bug
{
    return Bug::factory()->for($project)->for($reporter ?? arReporter($project), 'reporter')->create();
}

// -- Activity history ---------------------------------------------------------------

it('lists a bug activity history in deterministic sequence order', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $reporter = arReporter($project);
    $bug = arBug($project, $reporter);
    $developer = arDeveloper($project);

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/begin-review")->assertOk();
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", ['assignee_id' => $developer->id])->assertOk();

    $response = $this->actingAs($reporter)->getJson("/api/v1/bugs/{$bug->public_id}/activity");

    $response->assertOk();
    $sequences = collect($response->json('data'))->pluck('sequence');
    expect($sequences->toArray())->toBe($sequences->sort()->values()->toArray());
    expect($sequences->count())->toBeGreaterThanOrEqual(2);
});

it('serializes activity events with the documented ActivityEvent shape', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $bug = arBug($project);

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/begin-review")->assertOk();

    $response = $this->actingAs($admin)->getJson("/api/v1/bugs/{$bug->public_id}/activity");

    $response->assertOk();
    $event = collect($response->json('data'))->first();
    expect(array_keys($event))->toBe(['sequence', 'type', 'actor', 'occurred_at', 'before', 'after', 'reason_or_result']);
    expect($event['actor'])->toMatchArray(['id' => $admin->id, 'display_name' => $admin->display_name, 'active' => true]);
});

it('keeps the actor display name an immutable snapshot independent of later profile changes', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $bug = arBug($project);

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/begin-review")->assertOk();

    $admin->display_name = 'Renamed Later';
    $admin->save();

    $response = $this->actingAs($admin)->getJson("/api/v1/bugs/{$bug->public_id}/activity");

    $event = collect($response->json('data'))->firstWhere('type', 'bug.review_started');
    expect($event['actor']['display_name'])->not->toBe('Renamed Later');
});

it('denies activity history for a bug outside the viewer visibility as a non-disclosing 404', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $outsider = arReporter($projectB);
    $bug = arBug($projectA);

    $response = $this->actingAs($outsider)->getJson("/api/v1/bugs/{$bug->public_id}/activity");

    $response->assertStatus(404);
});

it('never allows an ActivityEvent to be mutated or deleted', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $bug = arBug($project);
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/begin-review")->assertOk();

    $event = ActivityEvent::where('bug_id', $bug->id)->firstOrFail();
    $event->reason_or_result = 'tampered';

    expect(fn () => $event->save())->toThrow(LogicException::class);
    expect(fn () => $event->delete())->toThrow(LogicException::class);
});

// -- Related-to -----------------------------------------------------------------------

it('lets an Admin create a Related-to relationship visible from both bugs', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $bugA = arBug($project);
    $bugB = arBug($project);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugA->public_id}/relationships", [
        'type' => 'related_to',
        'target_bug_id' => $bugB->public_id,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.type', 'related_to');
    $response->assertJsonPath('data.label', 'Related to');
});

it('collapses a symmetric Related-to relationship into one canonical row regardless of direction', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $bugA = arBug($project);
    $bugB = arBug($project);

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugA->public_id}/relationships", [
        'type' => 'related_to', 'target_bug_id' => $bugB->public_id,
    ])->assertCreated();

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugB->public_id}/relationships", [
        'type' => 'related_to', 'target_bug_id' => $bugA->public_id,
    ])->assertCreated();

    expect(BugRelationship::where('relationship_type', BugRelationshipType::RelatedTo->value)->count())->toBe(1);
});

it('shows the same symmetric Related-to label from either bug perspective', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $bugA = arBug($project);
    $bugB = arBug($project);
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugA->public_id}/relationships", [
        'type' => 'related_to', 'target_bug_id' => $bugB->public_id,
    ])->assertCreated();

    $fromA = $this->actingAs($admin)->getJson("/api/v1/bugs/{$bugA->public_id}/relationships")->json('data');
    $fromB = $this->actingAs($admin)->getJson("/api/v1/bugs/{$bugB->public_id}/relationships")->json('data');

    expect($fromA[0]['label'])->toBe('Related to');
    expect($fromB[0]['label'])->toBe('Related to');
});

it('rejects a self Related-to link', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $bug = arBug($project);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/relationships", [
        'type' => 'related_to', 'target_bug_id' => $bug->public_id,
    ]);

    $response->assertStatus(422);
});

it('rejects a cross-project Related-to target as non-disclosing', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $admin = arAdmin($projectA);
    $bugA = arBug($projectA);
    $foreignBug = arBug($projectB);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugA->public_id}/relationships", [
        'type' => 'related_to', 'target_bug_id' => $foreignBug->public_id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['target_bug_id']);
});

// -- Blocks -----------------------------------------------------------------------

it('lets an Admin create a directional Blocks relationship with forward and reverse labels', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $bugA = arBug($project);
    $bugB = arBug($project);

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugA->public_id}/relationships", [
        'type' => 'blocks', 'target_bug_id' => $bugB->public_id,
    ])->assertCreated()->assertJsonPath('data.label', 'Blocks');

    $fromB = $this->actingAs($admin)->getJson("/api/v1/bugs/{$bugB->public_id}/relationships")->json('data');
    expect($fromB[0]['label'])->toBe('Blocked by');
});

it('proves a Blocks relationship never gates any workflow command on the blocked bug', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $reporter = arReporter($project);
    $developer = arDeveloper($project);
    $blocker = arBug($project);
    $blocked = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$blocker->public_id}/relationships", [
        'type' => 'blocks', 'target_bug_id' => $blocked->public_id,
    ])->assertCreated();

    $blocked = (new AssignDeveloper())->handle($admin, $blocked->fresh(), $developer->id)->fresh();
    expect($blocked->status->value)->toBe('assigned');

    $this->actingAs($developer)->postJson("/api/v1/bugs/{$blocked->public_id}/start-work")
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress');

    $this->actingAs($developer)->postJson("/api/v1/bugs/{$blocked->public_id}/fixed-resolutions", [
        'explanation' => 'Fixed despite an unrelated Blocks link.',
        'qa_instructions' => 'Verify normally.',
    ])->assertOk()->assertJsonPath('data.status', 'qa_verification');
});

// -- Duplicate-of (Phase 7 compatibility) ------------------------------------------

it('creates a duplicate_of relationship through the general relationship endpoint by reusing EnsureDuplicateRelationship', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $original = arBug($project);
    $duplicate = arBug($project);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$duplicate->public_id}/relationships", [
        'type' => 'duplicate_of', 'target_bug_id' => $original->public_id,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.type', 'duplicate_of');
    $response->assertJsonPath('data.label', 'Duplicate of');
});

it('still rejects a duplicate_of cycle created directly through the relationship endpoint', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $bugA = arBug($project);
    $bugB = arBug($project);

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugA->public_id}/relationships", [
        'type' => 'duplicate_of', 'target_bug_id' => $bugB->public_id,
    ])->assertCreated();

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugB->public_id}/relationships", [
        'type' => 'duplicate_of', 'target_bug_id' => $bugA->public_id,
    ]);

    $response->assertStatus(409);
});

it('still lets RecordNonFixResolution create a duplicate relationship visible via the relationship endpoint', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $reporter = arReporter($project);
    $original = arBug($project, $reporter);
    $duplicate = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$duplicate->public_id}/non-fix-resolutions", [
        'outcome' => 'duplicate', 'reason' => 'Same issue.', 'duplicate_bug_id' => $original->public_id,
    ])->assertOk();

    $response = $this->actingAs($admin)->getJson("/api/v1/bugs/{$duplicate->public_id}/relationships");

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('type')->toArray())->toBe(['duplicate_of']);
});

// -- Authorization / non-disclosure ------------------------------------------------

it('denies relationship creation to non-Admin roles', function () {
    $project = Project::factory()->create();
    $developer = arDeveloper($project);
    $bugA = arBug($project);
    $bugB = arBug($project);

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bugA->public_id}/relationships", [
        'type' => 'related_to', 'target_bug_id' => $bugB->public_id,
    ]);

    $response->assertStatus(403);
});

it('lets any current project member read relationships, not only an Admin', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $reporter = arReporter($project);
    $bugA = arBug($project, $reporter);
    $bugB = arBug($project, $reporter);
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugA->public_id}/relationships", [
        'type' => 'related_to', 'target_bug_id' => $bugB->public_id,
    ])->assertCreated();

    $response = $this->actingAs($reporter)->getJson("/api/v1/bugs/{$bugA->public_id}/relationships");

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
});

it('denies relationship listing for a non-member as a non-disclosing 404', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $outsider = arReporter($projectB);
    $bug = arBug($projectA);

    $response = $this->actingAs($outsider)->getJson("/api/v1/bugs/{$bug->public_id}/relationships");

    $response->assertStatus(404);
});

// -- Deactivation -------------------------------------------------------------------

it('non-destructively deactivates a relationship, retaining the row and its history', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $bugA = arBug($project);
    $bugB = arBug($project);
    $created = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugA->public_id}/relationships", [
        'type' => 'related_to', 'target_bug_id' => $bugB->public_id,
    ])->assertCreated()->json('data');

    $response = $this->actingAs($admin)->deleteJson("/api/v1/relationships/{$created['id']}");

    $response->assertNoContent();
    $relationship = BugRelationship::findOrFail($created['id']);
    expect($relationship->is_active)->toBeFalse();
    expect($relationship->deactivated_at)->not->toBeNull();
    expect($relationship->deactivated_by_id)->toBe($admin->id);
    expect($relationship->created_by_id)->toBe($admin->id);

    $listing = $this->actingAs($admin)->getJson("/api/v1/bugs/{$bugA->public_id}/relationships")->json('data');
    expect($listing)->toHaveCount(0);
});

it('rejects a repeated deactivation as a conflict', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $bugA = arBug($project);
    $bugB = arBug($project);
    $created = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugA->public_id}/relationships", [
        'type' => 'related_to', 'target_bug_id' => $bugB->public_id,
    ])->assertCreated()->json('data');
    $this->actingAs($admin)->deleteJson("/api/v1/relationships/{$created['id']}")->assertNoContent();

    $response = $this->actingAs($admin)->deleteJson("/api/v1/relationships/{$created['id']}");

    $response->assertStatus(409);
});

it('denies relationship deactivation to non-Admin roles', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $developer = arDeveloper($project);
    $bugA = arBug($project);
    $bugB = arBug($project);
    $created = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugA->public_id}/relationships", [
        'type' => 'related_to', 'target_bug_id' => $bugB->public_id,
    ])->assertCreated()->json('data');

    $response = $this->actingAs($developer)->deleteJson("/api/v1/relationships/{$created['id']}");

    $response->assertStatus(403);
    expect(BugRelationship::findOrFail($created['id'])->is_active)->toBeTrue();
});

// -- Atomicity ----------------------------------------------------------------------

it('rolls back relationship creation entirely when its required ActivityEvent cannot be written', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $bugA = arBug($project);
    $bugB = arBug($project);

    $action = new class extends CreateBugRelationship
    {
        protected function recordBugEvent(...$args): ActivityEvent
        {
            throw new RuntimeException('simulated activity-event failure');
        }
    };

    expect(fn () => $action->handle($admin, $bugA, BugRelationshipType::RelatedTo, $bugB))->toThrow(RuntimeException::class);
    expect(BugRelationship::count())->toBe(0);
});

it('rolls back relationship deactivation entirely when its required ActivityEvent cannot be written', function () {
    $project = Project::factory()->create();
    $admin = arAdmin($project);
    $bugA = arBug($project);
    $bugB = arBug($project);
    $relationship = app(CreateBugRelationship::class)->handle($admin, $bugA, BugRelationshipType::RelatedTo, $bugB);

    $action = new class extends DeactivateBugRelationship
    {
        protected function recordBugEvent(...$args): ActivityEvent
        {
            throw new RuntimeException('simulated activity-event failure');
        }
    };

    expect(fn () => $action->handle($admin, $relationship))->toThrow(RuntimeException::class);
    expect($relationship->fresh()->is_active)->toBeTrue();
});
