<?php

use App\Actions\Bugs\AssignDeveloper;
use App\Actions\Bugs\RecordFixedResolution;
use App\Actions\Bugs\RecordNonFixResolution;
use App\Actions\Bugs\VerifyResolution;
use App\Enums\Role;
use App\Models\ActivityEvent;
use App\Models\Bug;
use App\Models\Membership;
use App\Models\Project;
use App\Models\QAVerificationResult;
use App\Models\ResolutionAttempt;
use App\Models\User;

function qaReporter(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Reporter)->create();

    return $user;
}

function qaAdmin(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Admin)->create();

    return $user;
}

function qaDeveloper(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Developer)->create();

    return $user;
}

function qaQA(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::QA)->create();

    return $user;
}

/**
 * Builds a Bug in QA Verification with a Fixed attempt recorded by $developer.
 */
function qaFixedAwaitingVerification(Project $project, User $developer): Bug
{
    $reporter = qaReporter($project);
    $admin = qaAdmin($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);
    $bug = (new AssignDeveloper())->handle($admin, $bug, $developer->id);
    $bug->status = 'in_progress';
    $bug->save();

    return (new RecordFixedResolution())->handle($developer, $bug->fresh(), 'Fixed it.', 'Verify the fix.');
}

function qaNonFixAwaitingVerification(Project $project, User $admin): Bug
{
    $reporter = qaReporter($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);

    return (new RecordNonFixResolution())->handle($admin, $bug, \App\Enums\ResolutionOutcome::WontFix, 'Not a priority.', [
        'decision_rationale' => 'Working as intended.',
    ]);
}

// -- QA eligibility ----------------------------------------------------------------

it('lets an eligible independent QA approve', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);

    $response = $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'approved',
        'notes' => 'Confirmed fixed on Safari.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'closed');
});

it('denies a non-QA project member from verifying', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $reporter = qaReporter($project);
    $bug = qaFixedAwaitingVerification($project, $developer);

    $response = $this->actingAs($reporter)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'approved', 'notes' => 'Looks fine.',
    ]);

    $response->assertStatus(403);
    expect($bug->fresh()->status->value)->toBe('qa_verification');
});

it('denies an Admin without an active QA role from verifying', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $admin = qaAdmin($project);
    $bug = qaFixedAwaitingVerification($project, $developer);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'approved', 'notes' => 'Approving as admin.',
    ]);

    $response->assertStatus(403);
});

it('denies verification when the QA membership is inactive', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $membership = Membership::where('project_id', $project->id)->where('user_id', $qa->id)->first();
    $membership->is_active = false;
    $membership->deactivated_at = now();
    $membership->save();

    $response = $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'approved', 'notes' => 'Trying anyway.',
    ]);

    $response->assertStatus(404);
});

it('denies verification when the QA role is inactive', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $role = Membership::where('project_id', $project->id)->where('user_id', $qa->id)->first()->roles()->first();
    $role->is_active = false;
    $role->deactivated_at = now();
    $role->save();

    $response = $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'approved', 'notes' => 'Trying anyway.',
    ]);

    $response->assertStatus(403);
});

it('denies the resolver from verifying their own resolution even if they also hold QA', function () {
    $project = Project::factory()->create();
    $developerQA = User::factory()->create();
    $admin = qaAdmin($project);
    Membership::factory()->for($project)->for($developerQA)->withRole(Role::Developer)->create();
    $membership = Membership::where('project_id', $project->id)->where('user_id', $developerQA->id)->first();
    $membership->roles()->create(['role' => Role::QA, 'granted_at' => now(), 'granted_by_id' => $admin->id]);

    $bug = qaFixedAwaitingVerification($project, $developerQA);

    $response = $this->actingAs($developerQA)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'approved', 'notes' => 'Approving my own fix.',
    ]);

    $response->assertStatus(403);
});

it('denies and non-discloses a foreign-project QA from verifying', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $developer = qaDeveloper($project);
    $foreignQA = qaQA($otherProject);
    $bug = qaFixedAwaitingVerification($project, $developer);

    $response = $this->actingAs($foreignQA)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'approved', 'notes' => 'Not my project.',
    ]);

    $response->assertStatus(404);
});

// -- Approval -----------------------------------------------------------------------

it('approves Fixed, closes the bug, and writes distinct approval and closure events', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);

    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'approved', 'notes' => 'Confirmed.',
    ])->assertOk();

    $bug->refresh();
    expect($bug->status->value)->toBe('closed');
    $attempt = ResolutionAttempt::where('bug_id', $bug->id)->sole();
    $result = QAVerificationResult::where('resolution_attempt_id', $attempt->id)->sole();
    expect($result->decision->value)->toBe('approved');
    expect($result->verifier_id)->toBe($qa->id);

    $events = ActivityEvent::where('bug_id', $bug->id)->orderBy('sequence')->pluck('event_type');
    expect($events->pop())->toBe('bug.closed');
    expect($events->pop())->toBe('bug.qa_approved');
});

it('approves each non-fix outcome and closes the bug', function (string $outcome, array $evidence) {
    $project = Project::factory()->create();
    $admin = qaAdmin($project);
    $qa = qaQA($project);
    $reporter = qaReporter($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);
    $bug = (new RecordNonFixResolution())->handle($admin, $bug, \App\Enums\ResolutionOutcome::from($outcome), 'Reason.', $evidence);

    $response = $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'approved', 'notes' => 'Evidence checks out.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'closed');
})->with([
    'cannot_reproduce' => ['cannot_reproduce', ['attempted_steps' => 'Tried everything.', 'environment' => 'macOS.']],
    'wont_fix' => ['wont_fix', ['decision_rationale' => 'By design.']],
]);

it('preserves the ResolutionAttempt after closure', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);

    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'approved', 'notes' => 'Confirmed.',
    ])->assertOk();

    expect(ResolutionAttempt::where('bug_id', $bug->id)->count())->toBe(1);
});

it('rejects a repeated verification attempt as a conflict without a second result', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);

    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'approved', 'notes' => 'First pass.',
    ])->assertOk();

    $response = $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'approved', 'notes' => 'Second pass.',
    ]);

    $response->assertStatus(409);
    $attempt = ResolutionAttempt::where('bug_id', $bug->id)->sole();
    expect(QAVerificationResult::where('resolution_attempt_id', $attempt->id)->count())->toBe(1);
});

// -- Fixed rejection ------------------------------------------------------------------

it('rejects Fixed, reopens, clears the active attempt, and preserves the failed attempt and result', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $failedAttemptId = $bug->active_resolution_attempt_id;

    $response = $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'rejected', 'notes' => 'Still broken on Safari.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'reopened');
    $bug->refresh();
    expect($bug->active_resolution_attempt_id)->toBeNull();
    expect(ResolutionAttempt::find($failedAttemptId))->not->toBeNull();
    expect(QAVerificationResult::where('resolution_attempt_id', $failedAttemptId)->sole()->decision->value)->toBe('rejected');
});

it('retains the eligible assignee after a rejected Fixed attempt', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);

    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'rejected', 'notes' => 'Nope.',
    ])->assertOk();

    expect($bug->fresh()->assigneeMembership->user_id)->toBe($developer->id);
});

it('lets the retained eligible Developer resume Reopened straight to In Progress', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", ['decision' => 'rejected', 'notes' => 'Nope.'])->assertOk();

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/resume-work");

    $response->assertOk();
    $response->assertJsonPath('data.status', 'in_progress');
});

it('denies the wrong Developer from resuming', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $otherDeveloper = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", ['decision' => 'rejected', 'notes' => 'Nope.'])->assertOk();

    $response = $this->actingAs($otherDeveloper)->postJson("/api/v1/bugs/{$bug->public_id}/resume-work");

    $response->assertStatus(403);
    expect($bug->fresh()->status->value)->toBe('reopened');
});

it('denies resume when the assignee membership has since been deactivated', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", ['decision' => 'rejected', 'notes' => 'Nope.'])->assertOk();

    $membership = $bug->fresh()->assigneeMembership;
    $membership->is_active = false;
    $membership->deactivated_at = now();
    $membership->save();

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/resume-work");

    $response->assertStatus(404);
});

it('routes an unassigned failed Fixed attempt through renewed Admin review, not direct resume', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $admin = qaAdmin($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", ['decision' => 'rejected', 'notes' => 'Nope.'])->assertOk();

    // Deactivate the Developer's role to simulate no-longer-eligible.
    $role = $bug->fresh()->assigneeMembership->roles()->first();
    $role->is_active = false;
    $role->deactivated_at = now();
    $role->save();

    $resumeAttempt = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/resume-work");
    $resumeAttempt->assertStatus(403);

    $renew = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/renew-review");
    $renew->assertOk();
    $renew->assertJsonPath('data.status', 'review');
});

// -- Non-fix rejection -----------------------------------------------------------------

it('routes a rejected non-fix outcome through renewed Admin review', function () {
    $project = Project::factory()->create();
    $admin = qaAdmin($project);
    $qa = qaQA($project);
    $bug = qaNonFixAwaitingVerification($project, $admin);
    $failedAttemptId = $bug->active_resolution_attempt_id;

    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", [
        'decision' => 'rejected', 'notes' => 'Disagree with the rationale.',
    ])->assertOk();

    expect($bug->fresh()->status->value)->toBe('reopened');

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/renew-review");
    $response->assertOk();
    $response->assertJsonPath('data.status', 'review');

    expect(ResolutionAttempt::find($failedAttemptId))->not->toBeNull();
    expect(QAVerificationResult::where('resolution_attempt_id', $failedAttemptId)->sole()->decision->value)->toBe('rejected');
});

it('does not allow direct Developer resume after a rejected non-fix outcome, even with an eligible assignee', function () {
    $project = Project::factory()->create();
    $admin = qaAdmin($project);
    $qa = qaQA($project);
    $developer = qaDeveloper($project);
    $reporter = qaReporter($project);
    $bug = Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'review']);
    $bug = (new AssignDeveloper())->handle($admin, $bug, $developer->id);
    $bug = (new RecordNonFixResolution())->handle($admin, $bug->fresh(), \App\Enums\ResolutionOutcome::WontFix, 'Not a priority.', [
        'decision_rationale' => 'Working as intended.',
    ]);
    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", ['decision' => 'rejected', 'notes' => 'No.'])->assertOk();

    // The Developer remains a fully eligible assignee, but the latest
    // rejected attempt is non-fix, so the resume-work path must still
    // refuse this — the outcome type, not just assignee eligibility, gates it.
    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/resume-work");

    $response->assertStatus(409);
});

// -- Closed reopen --------------------------------------------------------------------

it('lets an authorized Admin reopen a Closed bug with a reason', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $admin = qaAdmin($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", ['decision' => 'approved', 'notes' => 'Confirmed.'])->assertOk();

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/reopen", [
        'reason' => 'Customer reports it is still broken.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'review');
});

it('rejects a blank reopen reason', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $admin = qaAdmin($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", ['decision' => 'approved', 'notes' => 'Confirmed.'])->assertOk();

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/reopen", ['reason' => '   ']);

    $response->assertStatus(422);
});

it('denies a non-Admin from reopening a Closed bug', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", ['decision' => 'approved', 'notes' => 'Confirmed.'])->assertOk();

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/reopen", [
        'reason' => 'Trying to reopen my own bug.',
    ]);

    $response->assertStatus(403);
});

it('rejects a repeated reopen command as a conflict', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $admin = qaAdmin($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", ['decision' => 'approved', 'notes' => 'Confirmed.'])->assertOk();
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/reopen", ['reason' => 'First reopen.'])->assertOk();

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/reopen", ['reason' => 'Second reopen.']);

    $response->assertStatus(409);
});

it('preserves historical attempts and QA results through a Closed-bug reopen', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $admin = qaAdmin($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $attemptId = $bug->active_resolution_attempt_id;
    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", ['decision' => 'approved', 'notes' => 'Confirmed.'])->assertOk();

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/reopen", ['reason' => 'Regressed.'])->assertOk();

    expect(ResolutionAttempt::find($attemptId))->not->toBeNull();
    expect(QAVerificationResult::where('resolution_attempt_id', $attemptId)->sole()->decision->value)->toBe('approved');
    expect($bug->fresh()->active_resolution_attempt_id)->toBeNull();
});

// -- QA queue -----------------------------------------------------------------------

it('lists Bugs awaiting verification for an eligible independent QA', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);

    $response = $this->actingAs($qa)->getJson('/api/v1/bugs/qa-queue');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($bug->id);
});

it('excludes from the queue a resolution the QA user recorded themselves', function () {
    $project = Project::factory()->create();
    $developerQA = User::factory()->create();
    $admin = qaAdmin($project);
    Membership::factory()->for($project)->for($developerQA)->withRole(Role::Developer)->create();
    $membership = Membership::where('project_id', $project->id)->where('user_id', $developerQA->id)->first();
    $membership->roles()->create(['role' => Role::QA, 'granted_at' => now(), 'granted_by_id' => $admin->id]);
    $bug = qaFixedAwaitingVerification($project, $developerQA);

    $response = $this->actingAs($developerQA)->getJson('/api/v1/bugs/qa-queue');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($bug->id);
});

it('hides an unrelated project\'s awaiting-verification bugs from the queue', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $developer = qaDeveloper($otherProject);
    $qa = qaQA($project);
    $foreignBug = qaFixedAwaitingVerification($otherProject, $developer);

    $response = $this->actingAs($qa)->getJson('/api/v1/bugs/qa-queue');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($foreignBug->id);
});

it('immediately removes queue access once the QA grant is deactivated', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);

    $this->actingAs($qa)->getJson('/api/v1/bugs/qa-queue')->assertOk()->assertJsonPath('data.0.id', $bug->id);

    $membership = Membership::where('project_id', $project->id)->where('user_id', $qa->id)->first();
    $membership->is_active = false;
    $membership->deactivated_at = now();
    $membership->save();

    $response = $this->actingAs($qa)->getJson('/api/v1/bugs/qa-queue');
    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

// -- Atomicity ------------------------------------------------------------------------

it('rolls back verification entirely when its required ActivityEvent cannot be written', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $attemptId = $bug->active_resolution_attempt_id;

    $action = new class extends VerifyResolution
    {
        protected function recordBugEvent(...$args): ActivityEvent
        {
            throw new RuntimeException('simulated activity-event failure');
        }
    };

    expect(fn () => $action->handle($qa, $bug, \App\Enums\QAVerificationDecision::Approved, 'Confirmed.'))
        ->toThrow(RuntimeException::class);

    expect($bug->fresh()->status->value)->toBe('qa_verification');
    expect(QAVerificationResult::where('resolution_attempt_id', $attemptId)->count())->toBe(0);
});

it('enforces one QAVerificationResult per attempt at the database level', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $attemptId = $bug->active_resolution_attempt_id;

    QAVerificationResult::create([
        'resolution_attempt_id' => $attemptId, 'verifier_id' => $qa->id, 'decision' => 'approved',
        'verification_notes' => 'First.', 'created_at' => now(),
    ]);

    expect(fn () => QAVerificationResult::create([
        'resolution_attempt_id' => $attemptId, 'verifier_id' => $qa->id, 'decision' => 'rejected',
        'verification_notes' => 'Second.', 'created_at' => now(),
    ]))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

// -- Repeated cycles --------------------------------------------------------------------

it('keeps attempt #1\'s QA result preserved and independently verifies attempt #2', function () {
    $project = Project::factory()->create();
    $developer = qaDeveloper($project);
    $qa = qaQA($project);
    $bug = qaFixedAwaitingVerification($project, $developer);
    $attempt1Id = $bug->active_resolution_attempt_id;

    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", ['decision' => 'rejected', 'notes' => 'First fail.'])->assertOk();
    $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/resume-work")->assertOk();
    $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/fixed-resolutions", [
        'explanation' => 'Fixed for real.', 'qa_instructions' => 'Verify again.',
    ])->assertOk();

    $bug->refresh();
    $attempt2Id = $bug->active_resolution_attempt_id;
    expect($attempt2Id)->not->toBe($attempt1Id);

    $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/verifications", ['decision' => 'approved', 'notes' => 'Now confirmed.'])->assertOk();

    expect(QAVerificationResult::where('resolution_attempt_id', $attempt1Id)->sole()->decision->value)->toBe('rejected');
    expect(QAVerificationResult::where('resolution_attempt_id', $attempt2Id)->sole()->decision->value)->toBe('approved');
    expect($bug->fresh()->status->value)->toBe('closed');
});
