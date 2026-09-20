<?php

use App\Actions\Bugs\AssignDeveloper;
use App\Actions\Bugs\RecordFixedResolution;
use App\Enums\Role;
use App\Models\ActivityEvent;
use App\Models\Bug;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ResolutionAttempt;
use App\Models\User;

function drReporter(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Reporter)->create();

    return $user;
}

function drAdmin(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Admin)->create();

    return $user;
}

function drDeveloper(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Developer)->create();

    return $user;
}

/**
 * Builds a Bug already Assigned to $developer, ready for start-work tests.
 */
function drAssignedBug(Project $project, User $developer, string $status = 'assigned'): Bug
{
    $reporter = drReporter($project);
    $admin = drAdmin($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);

    $bug = (new AssignDeveloper())->handle($admin, $bug, $developer->id)->fresh();

    if ($status !== 'assigned') {
        $bug->status = $status;
        $bug->save();
    }

    return $bug->fresh();
}

// -- Start work -----------------------------------------------------------------

it('lets the assigned eligible Developer start work', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer);

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/start-work");

    $response->assertOk();
    $response->assertJsonPath('data.status', 'in_progress');
});

it('denies start work to a non-assignee Developer', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $otherDeveloper = drDeveloper($project);
    $bug = drAssignedBug($project, $developer);

    $response = $this->actingAs($otherDeveloper)->postJson("/api/v1/bugs/{$bug->public_id}/start-work");

    $response->assertStatus(403);
    expect($bug->fresh()->status->value)->toBe('assigned');
});

it('denies start work to a wrong-role user, including an Admin', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer);
    $admin = drAdmin($project);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/start-work");

    $response->assertStatus(403);
});

it('denies start work when the assignee membership is inactive, as a non-disclosing 404', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer);
    $membership = $bug->assigneeMembership;
    $membership->is_active = false;
    $membership->deactivated_at = now();
    $membership->save();

    // Deactivating the developer's only membership also removes their view
    // access to the project entirely, so the bug is reported missing, not
    // forbidden (FR-004/005) — same convention as everywhere else.
    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/start-work");

    $response->assertStatus(404);
    expect($bug->fresh()->status->value)->toBe('assigned');
});

it('denies start work when the Developer role is inactive', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer);
    $role = $bug->assigneeMembership->roles()->first();
    $role->is_active = false;
    $role->deactivated_at = now();
    $role->save();

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/start-work");

    $response->assertStatus(403);
});

it('rejects start work from every non-Assigned source status', function (string $status) {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer, $status);

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/start-work");

    $response->assertStatus(409);
})->with(['review', 'in_progress']);

it('rejects a repeated start-work command as a conflict', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer);

    $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/start-work")->assertOk();
    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/start-work");

    $response->assertStatus(409);
    expect(ActivityEvent::where('bug_id', $bug->id)->where('event_type', 'bug.work_started')->count())->toBe(1);
});

// -- Progress ---------------------------------------------------------------------

it('lets the assigned Developer add nonblank progress while In Progress', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer, 'in_progress');

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/progress-updates", [
        'body' => 'Reproduced locally, investigating root cause.',
    ]);

    $response->assertOk();
    expect($bug->progressUpdates()->count())->toBe(1);
    expect($bug->progressUpdates()->first()->author_id)->toBe($developer->id);
});

it('rejects blank or whitespace-only progress', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer, 'in_progress');

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/progress-updates", [
        'body' => '   ',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['body']);
});

it('denies progress from a non-assignee Developer', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $otherDeveloper = drDeveloper($project);
    $bug = drAssignedBug($project, $developer, 'in_progress');

    $response = $this->actingAs($otherDeveloper)->postJson("/api/v1/bugs/{$bug->public_id}/progress-updates", [
        'body' => 'Trying to hijack this.',
    ]);

    $response->assertStatus(403);
    expect($bug->progressUpdates()->count())->toBe(0);
});

it('keeps progress history immutable', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer, 'in_progress');
    $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/progress-updates", ['body' => 'Original note.'])->assertOk();

    $progress = $bug->progressUpdates()->first();
    $progress->body = 'Tampered.';

    expect(fn () => $progress->save())->toThrow(LogicException::class);
    expect(fn () => $progress->delete())->toThrow(LogicException::class);
    expect($progress->fresh()->body)->toBe('Original note.');
});

// -- Fixed resolution ---------------------------------------------------------------

it('lets only the assigned eligible Developer record Fixed from In Progress', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer, 'in_progress');

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/fixed-resolutions", [
        'explanation' => 'Added a null check before dereferencing the session.',
        'qa_instructions' => 'Log in on Safari and confirm the dashboard loads.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'qa_verification');
    $response->assertJsonPath('data.active_resolution.outcome', 'fixed');
    $response->assertJsonPath('data.active_resolution.attempt_number', 1);
    $response->assertJsonPath('data.active_resolution.explanation', 'Added a null check before dereferencing the session.');
    $response->assertJsonPath('data.active_resolution.qa_instructions', 'Log in on Safari and confirm the dashboard loads.');
    $response->assertJsonPath('data.active_resolution.recorded_by.id', $developer->id);
    $response->assertJsonPath('data.active_resolution.reproduction_attempts', null);
    $response->assertJsonPath('data.active_resolution.reproduction_environment', null);
    $response->assertJsonPath('data.active_resolution.decision_rationale', null);
});

it('denies Fixed resolution to a non-assignee Developer', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $otherDeveloper = drDeveloper($project);
    $bug = drAssignedBug($project, $developer, 'in_progress');

    $response = $this->actingAs($otherDeveloper)->postJson("/api/v1/bugs/{$bug->public_id}/fixed-resolutions", [
        'explanation' => 'Not my bug.',
        'qa_instructions' => 'N/A',
    ]);

    $response->assertStatus(403);
});

it('denies Admin authority alone from recording Fixed', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer, 'in_progress');
    $admin = drAdmin($project);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/fixed-resolutions", [
        'explanation' => 'Admin override attempt.',
        'qa_instructions' => 'N/A',
    ]);

    $response->assertStatus(403);
});

it('rejects Fixed resolution from a source status other than In Progress', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer, 'assigned');

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/fixed-resolutions", [
        'explanation' => 'Too early.',
        'qa_instructions' => 'N/A',
    ]);

    $response->assertStatus(409);
});

it('requires a nonblank explanation and QA instructions', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer, 'in_progress');

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/fixed-resolutions", [
        'explanation' => '  ',
        'qa_instructions' => '',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['explanation', 'qa_instructions']);
});

it('sets active_resolution_attempt_id to the newly created attempt for this same Bug', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer, 'in_progress');

    $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/fixed-resolutions", [
        'explanation' => 'Fixed it.',
        'qa_instructions' => 'Verify the fix.',
    ])->assertOk();

    $bug->refresh();
    $attempt = ResolutionAttempt::where('bug_id', $bug->id)->sole();
    expect($bug->active_resolution_attempt_id)->toBe($attempt->id);
    expect($attempt->bug_id)->toBe($bug->id);
    expect($attempt->attempt_number)->toBe(1);
});

it('records an ordered Resolved ActivityEvent and never closes the bug', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer, 'in_progress');

    $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/fixed-resolutions", [
        'explanation' => 'Fixed it.',
        'qa_instructions' => 'Verify the fix.',
    ])->assertOk();

    $events = ActivityEvent::where('bug_id', $bug->id)->orderBy('sequence')->pluck('event_type');
    expect($events->last())->toBe('bug.resolved');
    expect($bug->fresh()->status->value)->not->toBe('closed');
});

it('rolls back the Fixed resolution when its required ActivityEvent cannot be written', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer, 'in_progress');

    $action = new class extends RecordFixedResolution
    {
        protected function recordBugEvent(...$args): ActivityEvent
        {
            throw new RuntimeException('simulated activity-event failure');
        }
    };

    expect(fn () => $action->handle($developer, $bug, 'Fixed it.', 'Verify it.'))->toThrow(RuntimeException::class);
    expect($bug->fresh()->status->value)->toBe('in_progress');
    expect(ResolutionAttempt::where('bug_id', $bug->id)->count())->toBe(0);
});

// -- Non-fix resolutions --------------------------------------------------------------

it('lets an Admin within scope record Cannot Reproduce with its required evidence', function (string $status) {
    $project = Project::factory()->create();
    $reporter = drReporter($project);
    $admin = drAdmin($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => $status]);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/non-fix-resolutions", [
        'outcome' => 'cannot_reproduce',
        'reason' => 'Could not reproduce with given steps.',
        'attempted_steps' => 'Tried on Chrome, Firefox, and Safari.',
        'environment' => 'macOS 15, all major browsers.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'qa_verification');
    $response->assertJsonPath('data.active_resolution.outcome', 'cannot_reproduce');
    $response->assertJsonPath('data.active_resolution.reproduction_attempts', 'Tried on Chrome, Firefox, and Safari.');
    $response->assertJsonPath('data.active_resolution.reproduction_environment', 'macOS 15, all major browsers.');
    $response->assertJsonPath('data.active_resolution.decision_rationale', null);

    $detail = $this->actingAs($admin)->getJson("/api/v1/bugs/{$bug->public_id}");
    $detail->assertOk();
    $detail->assertJsonPath('data.active_resolution.reproduction_attempts', 'Tried on Chrome, Firefox, and Safari.');
    $detail->assertJsonPath('data.resolution_attempts.0.reproduction_environment', 'macOS 15, all major browsers.');
})->with(['review', 'assigned', 'in_progress']);

it('rejects Cannot Reproduce missing attempted_steps or environment', function () {
    $project = Project::factory()->create();
    $admin = drAdmin($project);
    $bug = Bug::factory()->for($project)->for(drReporter($project), 'reporter')->create(['status' => 'review']);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/non-fix-resolutions", [
        'outcome' => 'cannot_reproduce',
        'reason' => 'Could not reproduce.',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['attempted_steps', 'environment']);
});

it('lets an Admin within scope record Wont Fix with its decision rationale', function () {
    $project = Project::factory()->create();
    $admin = drAdmin($project);
    $bug = Bug::factory()->for($project)->for(drReporter($project), 'reporter')->create(['status' => 'review']);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/non-fix-resolutions", [
        'outcome' => 'wont_fix',
        'reason' => 'Not a priority.',
        'decision_rationale' => 'Working as intended per design doc v2.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.active_resolution.outcome', 'wont_fix');
    $response->assertJsonPath('data.active_resolution.reproduction_attempts', null);
    $response->assertJsonPath('data.active_resolution.reproduction_environment', null);
    $response->assertJsonPath('data.active_resolution.decision_rationale', 'Working as intended per design doc v2.');

    $detail = $this->actingAs($admin)->getJson("/api/v1/bugs/{$bug->public_id}");
    $detail->assertOk();
    $detail->assertJsonPath('data.resolution_attempts.0.decision_rationale', 'Working as intended per design doc v2.');
});

it('rejects Wont Fix missing a decision rationale', function () {
    $project = Project::factory()->create();
    $admin = drAdmin($project);
    $bug = Bug::factory()->for($project)->for(drReporter($project), 'reporter')->create(['status' => 'review']);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/non-fix-resolutions", [
        'outcome' => 'wont_fix',
        'reason' => 'Not a priority.',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['decision_rationale']);
});

it('denies non-fix resolution to a Developer or Reporter', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = Bug::factory()->for($project)->for(drReporter($project), 'reporter')->create(['status' => 'review']);

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/non-fix-resolutions", [
        'outcome' => 'wont_fix',
        'reason' => 'Trying to close it myself.',
        'decision_rationale' => 'N/A',
    ]);

    $response->assertStatus(403);
});

it('rejects a non-fix outcome from every source status other than Review, Assigned, or In Progress', function (string $status) {
    $project = Project::factory()->create();
    $admin = drAdmin($project);
    $bug = Bug::factory()->for($project)->for(drReporter($project), 'reporter')->create(['status' => $status]);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/non-fix-resolutions", [
        'outcome' => 'wont_fix',
        'reason' => 'N/A',
        'decision_rationale' => 'N/A',
    ]);

    $response->assertStatus(409);
})->with(['submitted', 'needs_information']);

it('rejects a repeated non-fix command as a conflict without creating a second attempt', function () {
    $project = Project::factory()->create();
    $admin = drAdmin($project);
    $bug = Bug::factory()->for($project)->for(drReporter($project), 'reporter')->create(['status' => 'review']);

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/non-fix-resolutions", [
        'outcome' => 'wont_fix', 'reason' => 'N/A', 'decision_rationale' => 'N/A',
    ])->assertOk();

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/non-fix-resolutions", [
        'outcome' => 'wont_fix', 'reason' => 'again', 'decision_rationale' => 'again',
    ]);

    $response->assertStatus(409);
    expect(ResolutionAttempt::where('bug_id', $bug->id)->count())->toBe(1);
});

// -- Duplicate resolution --------------------------------------------------------------

it('lets an Admin record Duplicate against a same-project target', function () {
    $project = Project::factory()->create();
    $admin = drAdmin($project);
    $reporter = drReporter($project);
    $original = Bug::factory()->for($project)->for($reporter, 'reporter')->create();
    $duplicate = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$duplicate->public_id}/non-fix-resolutions", [
        'outcome' => 'duplicate',
        'reason' => 'Already tracked.',
        'duplicate_bug_id' => $original->public_id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.active_resolution.outcome', 'duplicate');
});

it('rejects Duplicate against a cross-project target as non-disclosing', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $admin = drAdmin($project);
    $foreignOriginal = Bug::factory()->for($otherProject)->for(drReporter($otherProject), 'reporter')->create();
    $duplicate = Bug::factory()->for($project)->for(drReporter($project), 'reporter')->create(['status' => 'review']);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$duplicate->public_id}/non-fix-resolutions", [
        'outcome' => 'duplicate',
        'reason' => 'Already tracked elsewhere.',
        'duplicate_bug_id' => $foreignOriginal->public_id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['duplicate_bug_id']);
});

it('rejects a self-duplicate', function () {
    $project = Project::factory()->create();
    $admin = drAdmin($project);
    $bug = Bug::factory()->for($project)->for(drReporter($project), 'reporter')->create(['status' => 'review']);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/non-fix-resolutions", [
        'outcome' => 'duplicate',
        'reason' => 'Self reference.',
        'duplicate_bug_id' => $bug->public_id,
    ]);

    $response->assertStatus(422);
});

it('reuses the existing active duplicate_of link on a repeated identical duplicate command', function () {
    $project = Project::factory()->create();
    $admin = drAdmin($project);
    $reporter = drReporter($project);
    $original = Bug::factory()->for($project)->for($reporter, 'reporter')->create();
    $duplicateA = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$duplicateA->public_id}/non-fix-resolutions", [
        'outcome' => 'duplicate', 'reason' => 'Same issue.', 'duplicate_bug_id' => $original->public_id,
    ])->assertOk();

    expect(\App\Models\BugRelationship::where('source_bug_id', $duplicateA->id)
        ->where('target_bug_id', $original->id)->where('is_active', true)->count())->toBe(1);
});

it('rejects a direct duplicate cycle (A duplicate of B, then B duplicate of A)', function () {
    $project = Project::factory()->create();
    $admin = drAdmin($project);
    $reporter = drReporter($project);
    $bugA = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);
    $bugB = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugA->public_id}/non-fix-resolutions", [
        'outcome' => 'duplicate', 'reason' => 'A is dup of B.', 'duplicate_bug_id' => $bugB->public_id,
    ])->assertOk();

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugB->public_id}/non-fix-resolutions", [
        'outcome' => 'duplicate', 'reason' => 'B is dup of A.', 'duplicate_bug_id' => $bugA->public_id,
    ]);

    $response->assertStatus(409);
    expect(ResolutionAttempt::where('bug_id', $bugB->id)->count())->toBe(0);
});

it('rejects a multi-hop duplicate cycle (A dup of B, B dup of C, then C dup of A)', function () {
    $project = Project::factory()->create();
    $admin = drAdmin($project);
    $reporter = drReporter($project);
    $bugA = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);
    $bugB = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);
    $bugC = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugA->public_id}/non-fix-resolutions", [
        'outcome' => 'duplicate', 'reason' => 'A -> B.', 'duplicate_bug_id' => $bugB->public_id,
    ])->assertOk();
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugB->public_id}/non-fix-resolutions", [
        'outcome' => 'duplicate', 'reason' => 'B -> C.', 'duplicate_bug_id' => $bugC->public_id,
    ])->assertOk();

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugC->public_id}/non-fix-resolutions", [
        'outcome' => 'duplicate', 'reason' => 'C -> A.', 'duplicate_bug_id' => $bugA->public_id,
    ]);

    $response->assertStatus(409);
    expect(ResolutionAttempt::where('bug_id', $bugC->id)->count())->toBe(0);
});

it('rolls back the whole Duplicate resolution when the relationship cannot be created', function () {
    $project = Project::factory()->create();
    $admin = drAdmin($project);
    $reporter = drReporter($project);
    $bugA = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);
    $bugB = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bugA->public_id}/non-fix-resolutions", [
        'outcome' => 'duplicate',
        'reason' => 'Self after resolve attempt.',
        'duplicate_bug_id' => $bugA->public_id,
    ]);

    $response->assertStatus(422);
    expect(ResolutionAttempt::where('bug_id', $bugA->id)->count())->toBe(0);
    expect($bugA->fresh()->status->value)->toBe('review');
    expect(\App\Models\BugRelationship::where('source_bug_id', $bugA->id)->count())->toBe(0);
});

// -- Assigned work list --------------------------------------------------------------

it('lets a Developer see only their own currently assigned bugs', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $otherDeveloper = drDeveloper($project);
    $mine = drAssignedBug($project, $developer);
    $theirs = drAssignedBug($project, $otherDeveloper);

    $response = $this->actingAs($developer)->getJson('/api/v1/bugs/assigned-to-me');

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($mine->id);
    expect($ids)->not->toContain($theirs->id);
});

it('immediately removes assigned-work visibility once the membership is deactivated', function () {
    $project = Project::factory()->create();
    $developer = drDeveloper($project);
    $bug = drAssignedBug($project, $developer);

    $this->actingAs($developer)->getJson('/api/v1/bugs/assigned-to-me')
        ->assertOk()
        ->assertJsonPath('data.0.id', $bug->id);

    $membership = $bug->assigneeMembership;
    $membership->is_active = false;
    $membership->deactivated_at = now();
    $membership->save();

    $response = $this->actingAs($developer)->getJson('/api/v1/bugs/assigned-to-me');
    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('respects cross-project isolation in the assigned-work list', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $developer = User::factory()->create();
    Membership::factory()->for($projectA)->for($developer)->withRole(Role::Developer)->create();
    Membership::factory()->for($projectB)->for($developer)->withRole(Role::Developer)->create();

    $bugA = drAssignedBugForExistingDeveloper($projectA, $developer);

    $response = $this->actingAs($developer)->getJson('/api/v1/bugs/assigned-to-me');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($bugA->id);
});

function drAssignedBugForExistingDeveloper(Project $project, User $developer): Bug
{
    $reporter = drReporter($project);
    $admin = drAdmin($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);

    return (new AssignDeveloper())->handle($admin, $bug, $developer->id);
}
