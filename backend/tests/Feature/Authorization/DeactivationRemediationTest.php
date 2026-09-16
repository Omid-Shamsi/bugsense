<?php

use App\Enums\Role;
use App\Models\ActivityEvent;
use App\Models\Membership;
use App\Models\Project;
use App\Models\TrackingValue;
use App\Models\User;
use App\Support\Authorization;

function sysAdmin(): User
{
    return User::factory()->create(['is_system_admin' => true]);
}

it('deactivates a project without deleting it and records the event', function () {
    $project = Project::factory()->create();
    $actor = sysAdmin();

    $response = $this->actingAs($actor)->patchJson("/api/v1/projects/{$project->key}", [
        'active' => false,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.active', false);

    $project->refresh();
    expect($project->is_active)->toBeFalse();
    expect($project->deactivated_at)->not->toBeNull();
    expect($project->deactivated_by_id)->toBe($actor->id);
    expect(Project::find($project->id))->not->toBeNull();

    $event = ActivityEvent::where('subject_type', 'project')->where('subject_id', $project->id)
        ->where('event_type', 'project.deactivated')->first();
    expect($event)->not->toBeNull();
});

it('deactivates a user without deleting it', function () {
    $target = User::factory()->create();
    $actor = sysAdmin();

    $response = $this->actingAs($actor)->patchJson("/api/v1/users/{$target->id}", [
        'active' => false,
    ]);

    $response->assertOk();

    $target->refresh();
    expect($target->is_active)->toBeFalse();
    expect($target->deactivated_by_id)->toBe($actor->id);
    expect(User::find($target->id))->not->toBeNull();
});

it('immediately removes admin authorization when the granting membership is deactivated', function () {
    $project = Project::factory()->create();
    $projectAdmin = User::factory()->create();
    $membership = Membership::factory()->for($project)->for($projectAdmin)->withRole(Role::Admin)->create();
    $actor = sysAdmin();

    expect(Authorization::isProjectAdmin($projectAdmin, $project))->toBeTrue();

    $response = $this->actingAs($actor)->patchJson("/api/v1/memberships/{$membership->id}", [
        'active' => false,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.active', false);

    expect(Authorization::isProjectAdmin($projectAdmin->fresh(), $project->fresh()))->toBeFalse();

    // The deactivated project-scoped Admin can no longer act in that project.
    $followUp = $this->actingAs($projectAdmin)->patchJson("/api/v1/projects/{$project->key}", [
        'name' => 'Should not be allowed',
    ]);
    $followUp->assertStatus(403);
});

it('preserves attribution and history when reactivating a membership', function () {
    $project = Project::factory()->create();
    $membership = Membership::factory()->for($project)->withRole(Role::Reporter)->create();
    $actor = sysAdmin();

    $this->actingAs($actor)->patchJson("/api/v1/memberships/{$membership->id}", ['active' => false])->assertOk();
    expect($membership->fresh()->is_active)->toBeFalse();

    $response = $this->actingAs($actor)->patchJson("/api/v1/memberships/{$membership->id}", ['active' => true]);

    $response->assertOk();
    $membership->refresh();
    expect($membership->is_active)->toBeTrue();
    expect($membership->deactivated_at)->toBeNull();
    // The original row (and its id) is reused, not replaced.
    expect(Membership::where('id', $membership->id)->count())->toBe(1);
});

it('frees an active rank slot once the referencing tracking value is deactivated', function () {
    $project = Project::factory()->create();
    $original = TrackingValue::factory()->for($project)->create(['kind' => 'priority', 'code' => 'high', 'rank' => 1]);
    $actor = sysAdmin();

    $this->actingAs($actor)->patchJson("/api/v1/tracking-values/{$original->id}", ['active' => false])->assertOk();
    expect($original->fresh()->is_active)->toBeFalse();

    $response = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->key}/tracking-values", [
        'kind' => 'priority',
        'code' => 'urgent',
        'name' => 'Urgent',
        'rank' => 1,
    ]);

    $response->assertCreated();
});

it('retains the deactivated tracking value row for history instead of deleting it', function () {
    $project = Project::factory()->create();
    $trackingValue = TrackingValue::factory()->for($project)->create();
    $actor = sysAdmin();

    $this->actingAs($actor)->patchJson("/api/v1/tracking-values/{$trackingValue->id}", ['active' => false])->assertOk();

    expect(TrackingValue::find($trackingValue->id))->not->toBeNull();
    $event = ActivityEvent::where('subject_type', 'tracking_value')->where('subject_id', $trackingValue->id)
        ->where('event_type', 'tracking_value.deactivated')->first();
    expect($event)->not->toBeNull();
    expect($event->actor_id)->toBe($actor->id);
});

it('rejects hard deletion of a user with 409', function () {
    $target = User::factory()->create();
    $actor = sysAdmin();

    $response = $this->actingAs($actor)->deleteJson("/api/v1/users/{$target->id}");

    $response->assertStatus(409);
    expect(User::find($target->id))->not->toBeNull();
});

it('rejects hard deletion of a project with 409', function () {
    $project = Project::factory()->create();
    $actor = sysAdmin();

    $response = $this->actingAs($actor)->deleteJson("/api/v1/projects/{$project->key}");

    $response->assertStatus(409);
    expect(Project::find($project->id))->not->toBeNull();
});

it('rejects hard deletion of a membership with 409', function () {
    $project = Project::factory()->create();
    $membership = Membership::factory()->for($project)->create();
    $actor = sysAdmin();

    $response = $this->actingAs($actor)->deleteJson("/api/v1/memberships/{$membership->id}");

    $response->assertStatus(409);
    expect(Membership::find($membership->id))->not->toBeNull();
});

it('rejects hard deletion of a tracking value with 409', function () {
    $project = Project::factory()->create();
    $trackingValue = TrackingValue::factory()->for($project)->create();
    $actor = sysAdmin();

    $response = $this->actingAs($actor)->deleteJson("/api/v1/tracking-values/{$trackingValue->id}");

    $response->assertStatus(409);
    expect(TrackingValue::find($trackingValue->id))->not->toBeNull();
});

it('denies a project-scoped Admin from deactivating another project', function () {
    $ownProject = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->for($ownProject)->for($actor)->withRole(Role::Admin)->create();

    $response = $this->actingAs($actor)->patchJson("/api/v1/projects/{$otherProject->key}", [
        'active' => false,
    ]);

    $response->assertStatus(403);
    expect($otherProject->fresh()->is_active)->toBeTrue();
});
