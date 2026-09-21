<?php

use App\Enums\Role;
use App\Models\ActivityEvent;
use App\Models\Bug;
use App\Models\Membership;
use App\Models\Project;
use App\Models\TrackingValue;
use App\Models\User;

function reporterOf(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Reporter)->create();

    return $user;
}

function memberWithRoleOf(Project $project, Role $role): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole($role)->create();

    return $user;
}

function projectAdminWithRoleOf(Project $project, Role ...$roles): User
{
    $user = User::factory()->create();
    $membership = Membership::factory()->for($project)->for($user)->withRole(Role::Admin)->create();
    foreach ($roles as $role) {
        $membership->roles()->create(['role' => $role, 'granted_at' => now(), 'granted_by_id' => $user->id]);
    }

    return $user;
}

// -- Creation ---------------------------------------------------------------

it('lets a current Reporter create a bug that starts Submitted with a stable public ID', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);

    $response = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => 'Login button does nothing',
        'description' => 'Clicking login has no effect on Safari.',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.status', 'submitted');
    $response->assertJsonPath('data.title', 'Login button does nothing');
    $response->assertJsonPath('data.reporter.id', $reporter->id);
    $response->assertJsonPath('data.project.id', $project->id);
    expect($response->json('data.public_id'))->toMatch('/^BUG-\d{6,}$/');
});

it('denies bug creation to a non-Reporter project member', function () {
    $project = Project::factory()->create();
    $developer = memberWithRoleOf($project, Role::Developer);

    $response = $this->actingAs($developer)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => 'Should not be created',
        'description' => 'Denied.',
    ]);

    $response->assertStatus(403);
    expect(Bug::where('title', 'Should not be created')->exists())->toBeFalse();
});

it('denies bug creation to a user with no membership in the project', function () {
    $project = Project::factory()->create();
    $outsider = User::factory()->create();

    $response = $this->actingAs($outsider)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => 'Should not be created',
        'description' => 'Denied.',
    ]);

    $response->assertStatus(403);
});

it('requires a nonblank title and description', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);

    $response = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => '',
        'description' => '',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['title', 'description']);
});

it('rejects a title longer than 200 characters', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);

    $response = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => str_repeat('a', 201),
        'description' => 'Valid description.',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['title']);
});

it('accepts a title at exactly the 200 character boundary', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);

    $response = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => str_repeat('a', 200),
        'description' => 'Valid description.',
    ]);

    $response->assertCreated();
});

it('rejects a whitespace-only description as blank', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);

    $response = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => 'Valid title',
        'description' => '   ',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['description']);
});

it('keeps project and reporter attribution immutable and unique public IDs stable across bugs', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);

    $first = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id, 'title' => 'First', 'description' => 'First bug.',
    ])->assertCreated();
    $second = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id, 'title' => 'Second', 'description' => 'Second bug.',
    ])->assertCreated();

    expect($first->json('data.public_id'))->not->toBe($second->json('data.public_id'));

    $bug = Bug::where('title', 'First')->firstOrFail();
    expect($bug->project_id)->toBe($project->id);
    expect($bug->reporter_id)->toBe($reporter->id);
});

// -- Classification / tags ---------------------------------------------------

it('accepts a same-project category selected by the Reporter', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $category = TrackingValue::factory()->for($project)->create(['kind' => 'category', 'code' => 'ui']);

    $response = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => 'UI glitch',
        'description' => 'Visual bug.',
        'category_id' => $category->id,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.category.id', $category->id);
});

it('rejects a category from a different project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $reporter = reporterOf($project);
    $foreignCategory = TrackingValue::factory()->for($otherProject)->create(['kind' => 'category', 'code' => 'ui']);

    $response = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => 'UI glitch',
        'description' => 'Visual bug.',
        'category_id' => $foreignCategory->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['category_id']);
});

it('denies setting priority or severity when the Reporter has no Admin authority', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $priority = TrackingValue::factory()->for($project)->create(['kind' => 'priority', 'code' => 'high']);

    $response = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => 'Urgent issue',
        'description' => 'Needs attention.',
        'priority_id' => $priority->id,
    ]);

    $response->assertStatus(403);
    expect(Bug::where('title', 'Urgent issue')->exists())->toBeFalse();
});

it('allows setting priority and severity when the Reporter also holds Admin authority', function () {
    $project = Project::factory()->create();
    $reporterAdmin = projectAdminWithRoleOf($project, Role::Reporter);
    $priority = TrackingValue::factory()->for($project)->create(['kind' => 'priority', 'code' => 'high']);
    $severity = TrackingValue::factory()->for($project)->create(['kind' => 'severity', 'code' => 'major']);

    $response = $this->actingAs($reporterAdmin)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => 'Urgent issue',
        'description' => 'Needs attention.',
        'priority_id' => $priority->id,
        'severity_id' => $severity->id,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.priority.id', $priority->id);
    $response->assertJsonPath('data.severity.id', $severity->id);
});

it('attaches valid same-project tags and rejects a foreign-project tag', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $reporter = reporterOf($project);
    $tag = TrackingValue::factory()->for($project)->create(['kind' => 'tag', 'code' => 'regression']);
    $foreignTag = TrackingValue::factory()->for($otherProject)->create(['kind' => 'tag', 'code' => 'regression']);

    $ok = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => 'Tagged bug',
        'description' => 'Has tags.',
        'tag_ids' => [$tag->id],
    ]);
    $ok->assertCreated();
    $ok->assertJsonPath('data.tags.0.id', $tag->id);

    $rejected = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => 'Bad tag bug',
        'description' => 'Has a foreign tag.',
        'tag_ids' => [$foreignTag->id],
    ]);
    $rejected->assertStatus(422);
    $rejected->assertJsonValidationErrors(['tag_ids.0']);
});

it('does not create duplicate BugTag rows for a repeated tag id', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $tag = TrackingValue::factory()->for($project)->create(['kind' => 'tag', 'code' => 'regression']);

    $response = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id,
        'title' => 'Tagged twice',
        'description' => 'Same tag id sent twice.',
        'tag_ids' => [$tag->id, $tag->id],
    ]);

    $response->assertStatus(422);
});

// -- Visibility ---------------------------------------------------------------

it('lets every current project member view an accessible bug regardless of role', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $qa = memberWithRoleOf($project, Role::QA);

    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create();

    $response = $this->actingAs($qa)->getJson("/api/v1/bugs/{$bug->public_id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $bug->id);
});

it('denies visibility of a bug in a project the user is not a member of', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create();
    $outsider = User::factory()->create();

    $response = $this->actingAs($outsider)->getJson("/api/v1/bugs/{$bug->public_id}");

    $response->assertStatus(404);
    $response->assertHeader('Content-Type', 'application/problem+json');
});

it('never leaks a bug from one project into another project member\'s bug list', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $reporterA = reporterOf($projectA);
    $memberB = reporterOf($projectB);
    $bugA = Bug::factory()->for($projectA)->for($reporterA, 'reporter')->create();

    $response = $this->actingAs($memberB)->getJson('/api/v1/bugs');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($bugA->id);
});

it('lets a system-wide Admin view a bug without project membership', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create();
    $sysAdmin = User::factory()->create(['is_system_admin' => true]);

    $response = $this->actingAs($sysAdmin)->getJson("/api/v1/bugs/{$bug->public_id}");

    $response->assertOk();
});

// -- Editing ------------------------------------------------------------------

it('lets the Reporter edit their own bug while Submitted', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create();

    $response = $this->actingAs($reporter)->patchJson("/api/v1/bugs/{$bug->public_id}", [
        'title' => 'Updated title',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.title', 'Updated title');
});

it('lets the Reporter edit their own bug while Needs Information', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'needs_information']);

    $response = $this->actingAs($reporter)->patchJson("/api/v1/bugs/{$bug->public_id}", [
        'description' => 'Added the missing repro steps.',
    ]);

    $response->assertOk();
});

it('denies the Reporter from editing outside Submitted or Needs Information', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'in_progress']);

    $response = $this->actingAs($reporter)->patchJson("/api/v1/bugs/{$bug->public_id}", [
        'title' => 'Should be denied',
    ]);

    $response->assertStatus(403);
    expect($bug->fresh()->title)->not->toBe('Should be denied');
});

it('denies one Reporter from editing another Reporter\'s bug', function () {
    $project = Project::factory()->create();
    $owner = reporterOf($project);
    $otherReporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($owner, 'reporter')->create();

    $response = $this->actingAs($otherReporter)->patchJson("/api/v1/bugs/{$bug->public_id}", [
        'title' => 'Hijacked',
    ]);

    $response->assertStatus(403);
});

it('lets an in-scope Admin edit a bug regardless of status', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'in_progress']);
    $admin = projectAdminWithRoleOf($project);

    $response = $this->actingAs($admin)->patchJson("/api/v1/bugs/{$bug->public_id}", [
        'title' => 'Corrected by admin',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.title', 'Corrected by admin');
});

it('denies an Admin from a different project from editing this bug, as a non-disclosing 404', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $reporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create();
    $foreignAdmin = projectAdminWithRoleOf($otherProject);

    // The foreign Admin is not a member of $project at all, so the bug is
    // invisible to them, not merely off-limits: 404, not 403.
    $response = $this->actingAs($foreignAdmin)->patchJson("/api/v1/bugs/{$bug->public_id}", [
        'title' => 'Should be denied',
    ]);

    $response->assertStatus(404);
    expect($bug->fresh()->title)->not->toBe('Should be denied');
});

it('never allows immutable attribution fields to change via update', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create();
    $originalReporterId = $bug->reporter_id;
    $originalProjectId = $bug->project_id;
    $originalPublicNumber = $bug->public_number;

    $this->actingAs($reporter)->patchJson("/api/v1/bugs/{$bug->public_id}", [
        'title' => 'New title',
    ])->assertOk();

    $bug->refresh();
    expect($bug->reporter_id)->toBe($originalReporterId);
    expect($bug->project_id)->toBe($originalProjectId);
    expect($bug->public_number)->toBe($originalPublicNumber);
});

it('rejects an update that introduces a cross-project category', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $reporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create();
    $foreignCategory = TrackingValue::factory()->for($otherProject)->create(['kind' => 'category', 'code' => 'ui']);

    $response = $this->actingAs($reporter)->patchJson("/api/v1/bugs/{$bug->public_id}", [
        'category_id' => $foreignCategory->id,
    ]);

    $response->assertStatus(422);
});

// -- ActivityEvent -------------------------------------------------------------

it('records an immutable ordered ActivityEvent for creation and each update', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);

    $created = $this->actingAs($reporter)->postJson('/api/v1/bugs', [
        'project_id' => $project->id, 'title' => 'Sequenced', 'description' => 'Bug for sequence test.',
    ])->assertCreated();
    $bug = Bug::findOrFail($created->json('data.id'));

    $this->actingAs($reporter)->patchJson("/api/v1/bugs/{$bug->public_id}", ['title' => 'Sequenced v2'])->assertOk();
    $this->actingAs($reporter)->patchJson("/api/v1/bugs/{$bug->public_id}", ['title' => 'Sequenced v3'])->assertOk();

    $events = ActivityEvent::where('bug_id', $bug->id)->orderBy('sequence')->get();

    expect($events)->toHaveCount(3);
    expect($events->pluck('sequence')->all())->toBe([1, 2, 3]);
    expect($events->first()->event_type)->toBe('bug.created');
    expect($events->last()->event_type)->toBe('bug.updated');
    expect($events->last()->after_data['title'])->toBe('Sequenced v3');
});

it('rejects a duplicate sequence for the same bug at the database level', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create();

    ActivityEvent::create([
        'event_scope' => 'bug', 'project_id' => $project->id, 'bug_id' => $bug->id, 'sequence' => 1,
        'event_type' => 'bug.created', 'actor_id' => $reporter->id, 'actor_display' => $reporter->display_name,
        'occurred_at' => now(), 'command_id' => (string) \Illuminate\Support\Str::uuid(),
    ]);

    expect(fn () => ActivityEvent::create([
        'event_scope' => 'bug', 'project_id' => $project->id, 'bug_id' => $bug->id, 'sequence' => 1,
        'event_type' => 'bug.updated', 'actor_id' => $reporter->id, 'actor_display' => $reporter->display_name,
        'occurred_at' => now(), 'command_id' => (string) \Illuminate\Support\Str::uuid(),
    ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

it('never allows a Bug ActivityEvent to be updated or deleted', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create();

    $event = ActivityEvent::create([
        'event_scope' => 'bug', 'project_id' => $project->id, 'bug_id' => $bug->id, 'sequence' => 1,
        'event_type' => 'bug.created', 'actor_id' => $reporter->id, 'actor_display' => $reporter->display_name,
        'occurred_at' => now(), 'command_id' => (string) \Illuminate\Support\Str::uuid(),
    ]);

    $event->reason_or_result = 'tampered';
    expect(fn () => $event->save())->toThrow(\LogicException::class);
    expect(fn () => $event->delete())->toThrow(\LogicException::class);
});

it('rolls back the whole update when its required ActivityEvent cannot be written', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['title' => 'Original title']);

    $action = new class extends \App\Actions\Bugs\UpdateBug
    {
        protected function recordBugEvent(...$args): ActivityEvent
        {
            throw new \RuntimeException('simulated activity-event failure');
        }
    };

    expect(fn () => $action->handle($reporter, $bug, ['title' => 'Should not persist']))
        ->toThrow(\RuntimeException::class);

    expect($bug->fresh()->title)->toBe('Original title');
});

// -- allowed_actions ------------------------------------------------------------

it('advertises edit as an allowed action for the owning Reporter in Submitted', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create();

    $response = $this->actingAs($reporter)->getJson("/api/v1/bugs/{$bug->public_id}");

    $response->assertOk();
    expect($response->json('data.allowed_actions'))->toBe(['edit']);
});

it('does not advertise edit for a QA member who cannot edit the report', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $qa = memberWithRoleOf($project, Role::QA);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create();

    $response = $this->actingAs($qa)->getJson("/api/v1/bugs/{$bug->public_id}");

    $response->assertOk();
    expect($response->json('data.allowed_actions'))->toBe([]);
});

it('never advertises workflow actions beyond Phase 6 triage/assignment', function () {
    $project = Project::factory()->create();
    $reporter = reporterOf($project);
    $admin = projectAdminWithRoleOf($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create();

    $response = $this->actingAs($admin)->getJson("/api/v1/bugs/{$bug->public_id}");

    $response->assertOk();
    $actions = $response->json('data.allowed_actions');
    // Phase 6 triage/assignment actions are now genuinely implemented.
    expect($actions)->toContain('begin_review', 'set_priority', 'set_severity');
    // Later-phase development/QA/closure actions remain unimplemented.
    foreach (['start_work', 'resolve', 'verify', 'close', 'reopen'] as $future) {
        expect($actions)->not->toContain($future);
    }
});
