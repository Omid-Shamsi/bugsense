<?php

use App\Enums\Role;
use App\Models\ActivityEvent;
use App\Models\Membership;
use App\Models\Project;
use App\Models\TrackingValue;
use App\Models\User;

function admin(): User
{
    return User::factory()->create(['is_system_admin' => true]);
}

it('paginates and searches the system user directory', function () {
    User::factory()->create(['display_name' => 'Alice Anderson', 'email' => 'alice@example.com']);
    User::factory()->create(['display_name' => 'Bob Baker', 'email' => 'bob@example.com']);
    $actor = admin();

    $response = $this->actingAs($actor)->getJson('/api/v1/users?q=alice');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.display_name', 'Alice Anderson');
    $response->assertJsonStructure(['data', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
});

it('never exposes password or remember_token fields', function () {
    User::factory()->create();
    $actor = admin();

    $response = $this->actingAs($actor)->getJson('/api/v1/users');

    $response->assertOk();
    $response->assertJsonMissingPath('data.0.password');
    $response->assertJsonMissingPath('data.0.remember_token');
});

it('rejects user creation with an invalid payload', function () {
    $actor = admin();

    $response = $this->actingAs($actor)->postJson('/api/v1/users', [
        'email' => 'not-an-email',
        'display_name' => '',
        'password' => 'short',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['email', 'display_name', 'password']);
});

it('returns a non-disclosing problem+json 404 for a missing user', function () {
    $actor = admin();

    $response = $this->actingAs($actor)->getJson('/api/v1/users/'.\Illuminate\Support\Str::uuid());

    $response->assertStatus(404);
    $response->assertHeader('Content-Type', 'application/problem+json');
});

it('records an immutable ActivityEvent when a project is created', function () {
    $actor = admin();

    $response = $this->actingAs($actor)->postJson('/api/v1/projects', [
        'key' => 'DEMO',
        'name' => 'Demo',
    ]);

    $response->assertCreated();

    $project = Project::where('key', 'DEMO')->firstOrFail();
    $event = ActivityEvent::where('subject_type', 'project')->where('subject_id', $project->id)->first();

    expect($event)->not->toBeNull();
    expect($event->event_type)->toBe('project.created');
    expect($event->actor_id)->toBe($actor->id);
    expect($event->actor_display)->toBe($actor->display_name);
    expect($event->after_data)->toMatchArray(['key' => 'DEMO', 'name' => 'Demo']);
});

it('rejects a duplicate project key', function () {
    Project::factory()->create(['key' => 'DUP']);
    $actor = admin();

    $response = $this->actingAs($actor)->postJson('/api/v1/projects', [
        'key' => 'DUP',
        'name' => 'Duplicate',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['key']);
});

it('creates tracking values scoped to one project and kind', function () {
    $project = Project::factory()->create();
    $actor = admin();

    $response = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->key}/tracking-values", [
        'kind' => 'priority',
        'code' => 'high',
        'name' => 'High',
        'rank' => 1,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.project_id', $project->id);
    $response->assertJsonPath('data.kind', 'priority');
});

it('rejects a duplicate active rank for the same project and kind', function () {
    $project = Project::factory()->create();
    TrackingValue::factory()->for($project)->create(['kind' => 'priority', 'code' => 'high', 'rank' => 1]);
    $actor = admin();

    $response = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->key}/tracking-values", [
        'kind' => 'priority',
        'code' => 'urgent',
        'name' => 'Urgent',
        'rank' => 1,
    ]);

    $response->assertStatus(409);
});

it('allows the same code for a tracking value in two different projects', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    TrackingValue::factory()->for($projectA)->create(['kind' => 'category', 'code' => 'ui']);
    $actor = admin();

    $response = $this->actingAs($actor)->postJson("/api/v1/projects/{$projectB->key}/tracking-values", [
        'kind' => 'category',
        'code' => 'ui',
        'name' => 'UI',
    ]);

    $response->assertCreated();
});

it('rejects creating a second membership for the same user in the same project', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->create();
    $actor = admin();

    $response = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->key}/memberships", [
        'user_id' => $user->id,
        'roles' => ['reporter'],
    ]);

    $response->assertStatus(409);
});

it('reconciles a membership role set on update, granting and revoking in one request', function () {
    $project = Project::factory()->create();
    $membership = Membership::factory()->for($project)->withRole(Role::Reporter)->create();
    $actor = admin();

    $response = $this->actingAs($actor)->patchJson("/api/v1/memberships/{$membership->id}", [
        'roles' => ['developer', 'qa'],
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.roles', ['developer', 'qa']);

    $roles = $membership->fresh()->roles()->pluck('role', 'is_active');
    expect($membership->fresh()->activeRoles()->count())->toBe(2);
    expect($membership->roles()->where('role', 'reporter')->where('is_active', false)->exists())->toBeTrue();
});

it('rejects an unauthenticated request with a problem+json 401', function () {
    $response = $this->getJson('/api/v1/users');

    $response->assertStatus(401);
    $response->assertHeader('Content-Type', 'application/problem+json');
});

it('rolls back the whole change when its required ActivityEvent cannot be written', function () {
    $actor = admin();

    $action = new class extends \App\Actions\Administration\CreateProject
    {
        protected function recordAdministrativeEvent(...$args): ActivityEvent
        {
            throw new \RuntimeException('simulated activity-event failure');
        }
    };

    expect(fn () => $action->handle($actor, ['key' => 'ROLLBACK', 'name' => 'Rollback Test']))
        ->toThrow(\RuntimeException::class);

    expect(Project::where('key', 'ROLLBACK')->exists())->toBeFalse();
});

it('never allows an update to an ActivityEvent row', function () {
    $actor = admin();
    $this->actingAs($actor)->postJson('/api/v1/projects', ['key' => 'IMMUT', 'name' => 'Immutable'])->assertCreated();

    $event = ActivityEvent::where('event_type', 'project.created')->firstOrFail();
    $originalAfterData = $event->after_data;

    $event->after_data = ['tampered' => true];

    expect(fn () => $event->save())->toThrow(\LogicException::class);
    expect($event->fresh()->after_data)->toBe($originalAfterData);
});

it('never allows an ActivityEvent row to be deleted', function () {
    $actor = admin();
    $this->actingAs($actor)->postJson('/api/v1/projects', ['key' => 'NODEL', 'name' => 'No Delete'])->assertCreated();

    $event = ActivityEvent::where('event_type', 'project.created')->firstOrFail();

    expect(fn () => $event->delete())->toThrow(\LogicException::class);
    expect(ActivityEvent::find($event->id))->not->toBeNull();
});
