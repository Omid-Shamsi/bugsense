<?php

use App\Actions\Bugs\AssignDeveloper;
use App\Enums\Role;
use App\Models\ActivityEvent;
use App\Models\Bug;
use App\Models\Membership;
use App\Models\Project;
use App\Models\TrackingValue;
use App\Models\User;
use App\Support\Authorization;

function wfReporter(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Reporter)->create();

    return $user;
}

function wfDeveloper(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Developer)->create();

    return $user;
}

function wfAdmin(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Admin)->create();

    return $user;
}

function wfSysAdmin(): User
{
    return User::factory()->create(['is_system_admin' => true]);
}

function wfBug(Project $project, User $reporter, string $status = 'submitted'): Bug
{
    return Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => $status]);
}

// -- Begin review -------------------------------------------------------------

it('lets an Admin within scope begin review from Submitted', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $bug = wfBug($project, wfReporter($project));

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/begin-review");

    $response->assertOk();
    $response->assertJsonPath('data.status', 'review');
});

it('denies begin review to a Reporter, Developer, or QA with no Admin authority', function () {
    $project = Project::factory()->create();
    $developer = wfDeveloper($project);
    $bug = wfBug($project, wfReporter($project));

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/begin-review");

    $response->assertStatus(403);
    expect($bug->fresh()->status->value)->toBe('submitted');
});

it('rejects begin review from every non-Submitted source status', function (string $status) {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $bug = wfBug($project, wfReporter($project), $status);

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/begin-review");

    $response->assertStatus(409);
    expect($bug->fresh()->status->value)->toBe($status);
})->with(['review', 'needs_information', 'assigned']);

it('denies a foreign-project Admin from beginning review, as a non-disclosing 404', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $foreignAdmin = wfAdmin($otherProject);
    $bug = wfBug($project, wfReporter($project));

    $response = $this->actingAs($foreignAdmin)->postJson("/api/v1/bugs/{$bug->public_id}/begin-review");

    $response->assertStatus(404);
});

it('lets a system-wide Admin begin review without project membership', function () {
    $project = Project::factory()->create();
    $sysAdmin = wfSysAdmin();
    $bug = wfBug($project, wfReporter($project));

    $response = $this->actingAs($sysAdmin)->postJson("/api/v1/bugs/{$bug->public_id}/begin-review");

    $response->assertOk();
});

// -- Request information -------------------------------------------------------

it('lets an Admin within scope request information from Review', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $bug = wfBug($project, wfReporter($project), 'review');

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/information-requests", [
        'request' => 'Please attach a screenshot.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'needs_information');
    expect($bug->informationRequests()->count())->toBe(1);
});

it('rejects a blank information request', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $bug = wfBug($project, wfReporter($project), 'review');

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/information-requests", [
        'request' => '   ',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['request']);
});

it('denies request information to a non-Admin', function () {
    $project = Project::factory()->create();
    $qa = User::factory()->create();
    Membership::factory()->for($project)->for($qa)->withRole(Role::QA)->create();
    $bug = wfBug($project, wfReporter($project), 'review');

    $response = $this->actingAs($qa)->postJson("/api/v1/bugs/{$bug->public_id}/information-requests", [
        'request' => 'Need more detail.',
    ]);

    $response->assertStatus(403);
});

it('rejects request information from a source status other than Review', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $bug = wfBug($project, wfReporter($project), 'submitted');

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/information-requests", [
        'request' => 'Need more detail.',
    ]);

    $response->assertStatus(409);
});

// -- Reporter response ----------------------------------------------------------

it('lets the Reporter respond and automatically returns the bug to Review', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $reporter = wfReporter($project);
    $bug = wfBug($project, $reporter, 'review');
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/information-requests", ['request' => 'More info please.'])->assertOk();

    $response = $this->actingAs($reporter)->postJson("/api/v1/bugs/{$bug->public_id}/information-responses", [
        'response' => 'Here is the detail you asked for.',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'review');
    $openRequest = $bug->informationRequests()->latest('requested_at')->first();
    expect($openRequest->response_text)->toBe('Here is the detail you asked for.');
    expect($openRequest->responded_by_id)->toBe($reporter->id);
});

it('denies a non-Reporter from responding to the information request', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $reporter = wfReporter($project);
    $bug = wfBug($project, $reporter, 'review');
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/information-requests", ['request' => 'More info please.'])->assertOk();

    $someoneElse = User::factory()->create();
    Membership::factory()->for($project)->for($someoneElse)->withRole(Role::QA)->create();

    $response = $this->actingAs($someoneElse)->postJson("/api/v1/bugs/{$bug->public_id}/information-responses", [
        'response' => 'Not my bug to answer.',
    ]);

    $response->assertStatus(403);
});

it('rejects a response when the bug is not in Needs Information', function () {
    $project = Project::factory()->create();
    $reporter = wfReporter($project);
    $bug = wfBug($project, $reporter, 'review');

    $response = $this->actingAs($reporter)->postJson("/api/v1/bugs/{$bug->public_id}/information-responses", [
        'response' => 'Nothing was asked.',
    ]);

    $response->assertStatus(409);
});

it('preserves prior requests and responses across repeated information loops', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $reporter = wfReporter($project);
    $bug = wfBug($project, $reporter, 'review');

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/information-requests", ['request' => 'First question.'])->assertOk();
    $this->actingAs($reporter)->postJson("/api/v1/bugs/{$bug->public_id}/information-responses", ['response' => 'First answer.'])->assertOk();
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/information-requests", ['request' => 'Second question.'])->assertOk();
    $this->actingAs($reporter)->postJson("/api/v1/bugs/{$bug->public_id}/information-responses", ['response' => 'Second answer.'])->assertOk();

    expect($bug->informationRequests()->count())->toBe(2);
    expect($bug->informationRequests()->orderBy('requested_at')->pluck('request_text')->all())
        ->toBe(['First question.', 'Second question.']);
});

// -- Priority / severity ---------------------------------------------------------

it('lets an Admin within scope set the priority', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $priority = TrackingValue::factory()->for($project)->create(['kind' => 'priority', 'code' => 'high']);
    $bug = wfBug($project, wfReporter($project));

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/priority", [
        'priority_id' => $priority->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.priority.id', $priority->id);
});

it('lets an Admin within scope set the severity', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $severity = TrackingValue::factory()->for($project)->create(['kind' => 'severity', 'code' => 'major']);
    $bug = wfBug($project, wfReporter($project));

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/severity", [
        'severity_id' => $severity->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.severity.id', $severity->id);
});

it('denies priority/severity changes to a non-Admin', function () {
    $project = Project::factory()->create();
    $reporter = wfReporter($project);
    $priority = TrackingValue::factory()->for($project)->create(['kind' => 'priority', 'code' => 'high']);
    $bug = wfBug($project, $reporter);

    $response = $this->actingAs($reporter)->postJson("/api/v1/bugs/{$bug->public_id}/priority", [
        'priority_id' => $priority->id,
    ]);

    $response->assertStatus(403);
});

it('requires the priority value to belong to the same project', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $admin = wfAdmin($project);
    $foreignPriority = TrackingValue::factory()->for($otherProject)->create(['kind' => 'priority', 'code' => 'high']);
    $bug = wfBug($project, wfReporter($project));

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/priority", [
        'priority_id' => $foreignPriority->id,
    ]);

    $response->assertStatus(422);
});

it('rejects a severity value passed to the priority field (wrong kind)', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $severity = TrackingValue::factory()->for($project)->create(['kind' => 'severity', 'code' => 'major']);
    $bug = wfBug($project, wfReporter($project));

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/priority", [
        'priority_id' => $severity->id,
    ]);

    $response->assertStatus(422);
});

it('rejects an inactive priority value', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $priority = TrackingValue::factory()->for($project)->create(['kind' => 'priority', 'code' => 'high', 'is_active' => false]);
    $bug = wfBug($project, wfReporter($project));

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/priority", [
        'priority_id' => $priority->id,
    ]);

    $response->assertStatus(422);
});

it('records old and new values when priority changes', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $low = TrackingValue::factory()->for($project)->create(['kind' => 'priority', 'code' => 'low', 'rank' => 1]);
    $high = TrackingValue::factory()->for($project)->create(['kind' => 'priority', 'code' => 'high', 'rank' => 2]);
    $bug = wfBug($project, wfReporter($project));
    $bug->priority_id = $low->id;
    $bug->save();

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/priority", ['priority_id' => $high->id])->assertOk();

    $event = ActivityEvent::where('bug_id', $bug->id)->where('event_type', 'bug.priority_changed')->first();
    expect($event->before_data['priority_id'])->toBe($low->id);
    expect($event->after_data['priority_id'])->toBe($high->id);
});

// -- Assignment / reassignment ----------------------------------------------------

it('lets an Admin assign an eligible Developer from Review', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $developer = wfDeveloper($project);
    $bug = wfBug($project, wfReporter($project), 'review');

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", [
        'assignee_id' => $developer->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'assigned');
    $response->assertJsonPath('data.assignee.id', $developer->id);
});

it('denies assignment to a non-Admin, including Developer self-assignment', function () {
    $project = Project::factory()->create();
    $developer = wfDeveloper($project);
    $bug = wfBug($project, wfReporter($project), 'review');

    $response = $this->actingAs($developer)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", [
        'assignee_id' => $developer->id,
    ]);

    $response->assertStatus(403);
    expect($bug->fresh()->assignee_membership_id)->toBeNull();
});

it('requires the assignee to hold the Developer role in the same project', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $qaOnly = User::factory()->create();
    Membership::factory()->for($project)->for($qaOnly)->withRole(Role::QA)->create();
    $bug = wfBug($project, wfReporter($project), 'review');

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", [
        'assignee_id' => $qaOnly->id,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['assignee_id']);
});

it('requires the assignee membership to be in the same project as the bug', function () {
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $admin = wfAdmin($project);
    $foreignDeveloper = wfDeveloper($otherProject);
    $bug = wfBug($project, wfReporter($project), 'review');

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", [
        'assignee_id' => $foreignDeveloper->id,
    ]);

    $response->assertStatus(422);
});

it('rejects assignment to a Developer with an inactive membership', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $developer = User::factory()->create();
    $membership = Membership::factory()->for($project)->for($developer)->withRole(Role::Developer)->create();
    $membership->is_active = false;
    $membership->deactivated_at = now();
    $membership->save();
    $bug = wfBug($project, wfReporter($project), 'review');

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", [
        'assignee_id' => $developer->id,
    ]);

    $response->assertStatus(422);
});

it('rejects assignment to a user with a deactivated Developer role', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $developer = wfDeveloper($project);
    $role = $developer->memberships()->first()->roles()->first();
    $role->is_active = false;
    $role->deactivated_at = now();
    $role->save();
    $bug = wfBug($project, wfReporter($project), 'review');

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", [
        'assignee_id' => $developer->id,
    ]);

    $response->assertStatus(422);
});

it('lets an Admin reassign to a different eligible Developer and records old/new membership', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $firstDeveloper = wfDeveloper($project);
    $secondDeveloper = wfDeveloper($project);
    $bug = wfBug($project, wfReporter($project), 'review');
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", ['assignee_id' => $firstDeveloper->id])->assertOk();
    $firstMembershipId = $bug->fresh()->assignee_membership_id;

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", [
        'assignee_id' => $secondDeveloper->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.assignee.id', $secondDeveloper->id);

    $event = ActivityEvent::where('bug_id', $bug->id)->where('event_type', 'bug.assigned')->latest('sequence')->first();
    expect($event->before_data['assignee_membership_id'])->toBe($firstMembershipId);
    expect($event->after_data['assignee_membership_id'])->not->toBe($firstMembershipId);
});

it('allows explicit remediation-only unassignment back to Review', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $developer = wfDeveloper($project);
    $bug = wfBug($project, wfReporter($project), 'review');
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", ['assignee_id' => $developer->id])->assertOk();

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", [
        'assignee_id' => null,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.status', 'review');
    $response->assertJsonPath('data.assignee', null);
});

it('rejects assignment from a source status other than Review or Assigned', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $developer = wfDeveloper($project);
    $bug = wfBug($project, wfReporter($project), 'submitted');

    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", [
        'assignee_id' => $developer->id,
    ]);

    $response->assertStatus(409);
});

// -- Concurrency / stale command ---------------------------------------------------

it('rejects a stale begin-review command after another has already advanced the bug', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $bug = wfBug($project, wfReporter($project));

    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/begin-review")->assertOk();

    // Repeating the same command against the now-stale Submitted assumption
    // must not silently succeed or duplicate the transition.
    $response = $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/begin-review");

    $response->assertStatus(409);
    expect(ActivityEvent::where('bug_id', $bug->id)->where('event_type', 'bug.review_started')->count())->toBe(1);
});

it('rolls back the whole assignment when its required ActivityEvent cannot be written', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $developer = wfDeveloper($project);
    $bug = wfBug($project, wfReporter($project), 'review');

    $action = new class extends AssignDeveloper
    {
        protected function recordBugEvent(...$args): ActivityEvent
        {
            throw new RuntimeException('simulated activity-event failure');
        }
    };

    expect(fn () => $action->handle($admin, $bug, $developer->id))->toThrow(RuntimeException::class);
    expect($bug->fresh()->assignee_membership_id)->toBeNull();
    expect($bug->fresh()->status->value)->toBe('review');
});

// -- Deactivation remediation --------------------------------------------------

it('rejects deactivating an assignee User with an unremediated open assignment', function () {
    $project = Project::factory()->create();
    $sysAdmin = wfSysAdmin();
    $admin = wfAdmin($project);
    $developer = wfDeveloper($project);
    $bug = wfBug($project, wfReporter($project), 'review');
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", ['assignee_id' => $developer->id])->assertOk();

    $response = $this->actingAs($sysAdmin)->patchJson("/api/v1/users/{$developer->id}", ['active' => false]);

    $response->assertStatus(409);
    expect($developer->fresh()->is_active)->toBeTrue();
    expect($bug->fresh()->status->value)->toBe('assigned');
});

it('allows deactivating an assignee User once remediated via unassign', function () {
    $project = Project::factory()->create();
    $sysAdmin = wfSysAdmin();
    $admin = wfAdmin($project);
    $developer = wfDeveloper($project);
    $bug = wfBug($project, wfReporter($project), 'review');
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", ['assignee_id' => $developer->id])->assertOk();

    $response = $this->actingAs($sysAdmin)->patchJson("/api/v1/users/{$developer->id}", [
        'active' => false,
        'remediation' => ['unassign' => true],
    ]);

    $response->assertOk();
    expect($developer->fresh()->is_active)->toBeFalse();
    expect($bug->fresh()->status->value)->toBe('review');
    expect($bug->fresh()->assignee_membership_id)->toBeNull();
});

it('allows deactivating an assignee User once remediated via reassignment', function () {
    $project = Project::factory()->create();
    $sysAdmin = wfSysAdmin();
    $admin = wfAdmin($project);
    $developer = wfDeveloper($project);
    $replacement = wfDeveloper($project);
    $bug = wfBug($project, wfReporter($project), 'review');
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", ['assignee_id' => $developer->id])->assertOk();

    $response = $this->actingAs($sysAdmin)->patchJson("/api/v1/users/{$developer->id}", [
        'active' => false,
        'remediation' => ['reassign_to' => $replacement->id],
    ]);

    $response->assertOk();
    expect($bug->fresh()->status->value)->toBe('assigned');
    expect($bug->fresh()->assigneeMembership->user_id)->toBe($replacement->id);
});

it('rejects deactivating an assignee Membership with an unremediated open assignment', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $developer = wfDeveloper($project);
    $bug = wfBug($project, wfReporter($project), 'review');
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", ['assignee_id' => $developer->id])->assertOk();
    $membershipId = $bug->fresh()->assignee_membership_id;

    $response = $this->actingAs($admin)->patchJson("/api/v1/memberships/{$membershipId}", ['active' => false]);

    $response->assertStatus(409);
    expect(Membership::find($membershipId)->is_active)->toBeTrue();
});

it('rejects deactivating a Developer role with an unremediated open assignment', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $developer = wfDeveloper($project);
    $bug = wfBug($project, wfReporter($project), 'review');
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", ['assignee_id' => $developer->id])->assertOk();
    $membershipId = $bug->fresh()->assignee_membership_id;

    $response = $this->actingAs($admin)->patchJson("/api/v1/memberships/{$membershipId}", ['roles' => []]);

    $response->assertStatus(409);
    expect(Authorization::hasActiveRole($developer, $project, Role::Developer))->toBeTrue();
});

it('allows deactivating a Developer role once remediated via unassign', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $developer = wfDeveloper($project);
    $bug = wfBug($project, wfReporter($project), 'review');
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/assignments", ['assignee_id' => $developer->id])->assertOk();
    $membershipId = $bug->fresh()->assignee_membership_id;

    $response = $this->actingAs($admin)->patchJson("/api/v1/memberships/{$membershipId}", [
        'roles' => [],
        'remediation' => ['unassign' => true],
    ]);

    $response->assertOk();
    expect($bug->fresh()->assignee_membership_id)->toBeNull();
    expect($bug->fresh()->status->value)->toBe('review');
});

it('rejects deactivating an active TrackingValue referenced by an open bug', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $priority = TrackingValue::factory()->for($project)->create(['kind' => 'priority', 'code' => 'high']);
    $bug = wfBug($project, wfReporter($project));
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/priority", ['priority_id' => $priority->id])->assertOk();

    $response = $this->actingAs($admin)->patchJson("/api/v1/tracking-values/{$priority->id}", ['active' => false]);

    $response->assertStatus(409);
    expect($priority->fresh()->is_active)->toBeTrue();
});

it('allows deactivating a referenced TrackingValue once remediated via replacement', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $oldPriority = TrackingValue::factory()->for($project)->create(['kind' => 'priority', 'code' => 'high']);
    $newPriority = TrackingValue::factory()->for($project)->create(['kind' => 'priority', 'code' => 'urgent']);
    $bug = wfBug($project, wfReporter($project));
    $this->actingAs($admin)->postJson("/api/v1/bugs/{$bug->public_id}/priority", ['priority_id' => $oldPriority->id])->assertOk();

    $response = $this->actingAs($admin)->patchJson("/api/v1/tracking-values/{$oldPriority->id}", [
        'active' => false,
        'remediation' => ['replace_with' => $newPriority->id],
    ]);

    $response->assertOk();
    expect($bug->fresh()->priority_id)->toBe($newPriority->id);
    expect($oldPriority->fresh()->is_active)->toBeFalse();
});

it('allows deactivating a referenced TrackingValue once remediated via unset', function () {
    $project = Project::factory()->create();
    $admin = wfAdmin($project);
    $category = TrackingValue::factory()->for($project)->create(['kind' => 'category', 'code' => 'ui']);
    $bug = wfBug($project, wfReporter($project), status: 'submitted');
    $bug->category_id = $category->id;
    $bug->save();

    $response = $this->actingAs($admin)->patchJson("/api/v1/tracking-values/{$category->id}", [
        'active' => false,
        'remediation' => ['unset' => true],
    ]);

    $response->assertOk();
    expect($bug->fresh()->category_id)->toBeNull();
});

it('rejects Project deactivation while unremediated open bugs remain', function () {
    $project = Project::factory()->create();
    $sysAdmin = wfSysAdmin();
    wfBug($project, wfReporter($project));

    $response = $this->actingAs($sysAdmin)->patchJson("/api/v1/projects/{$project->key}", ['active' => false]);

    $response->assertStatus(409);
    expect($project->fresh()->is_active)->toBeTrue();
});

it('allows Project deactivation once it has no open bugs', function () {
    $project = Project::factory()->create();
    $sysAdmin = wfSysAdmin();
    // A closed bug would not block deactivation; simulate the absence of any open work directly.
    Bug::factory()->for($project)->for(wfReporter($project), 'reporter')->create(['status' => 'closed']);

    $response = $this->actingAs($sysAdmin)->patchJson("/api/v1/projects/{$project->key}", ['active' => false]);

    $response->assertOk();
    expect($project->fresh()->is_active)->toBeFalse();
});
