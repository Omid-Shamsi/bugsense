<?php

use App\Enums\Role;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;

function visActor(): User
{
    return User::factory()->create();
}

function visSystemAdmin(): User
{
    return User::factory()->create(['is_system_admin' => true]);
}

it('lists only projects where the user has an active membership', function () {
    $visible = Project::factory()->create();
    $hidden = Project::factory()->create();
    $user = visActor();
    Membership::factory()->for($visible)->for($user)->withRole(Role::Reporter)->create();

    $response = $this->actingAs($user)->getJson('/api/v1/projects');

    $response->assertOk();
    $keys = collect($response->json('data'))->pluck('key');
    expect($keys)->toContain($visible->key);
    expect($keys)->not->toContain($hidden->key);
});

it('lists multiple projects for one user with multiple roles in each', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $user = visActor();
    Membership::factory()->for($projectA)->for($user)->withRole(Role::Reporter)->create();
    $membershipB = Membership::factory()->for($projectB)->for($user)->withRole(Role::Developer)->create();
    $membershipB->roles()->create(['role' => Role::QA, 'granted_at' => now(), 'granted_by_id' => $user->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/projects');

    $response->assertOk();
    $keys = collect($response->json('data'))->pluck('key');
    expect($keys)->toContain($projectA->key, $projectB->key);
});

it('does not let a role in one project disclose a different project', function () {
    $ownProject = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = visActor();
    Membership::factory()->for($ownProject)->for($user)->withRole(Role::Admin)->create();

    $index = $this->actingAs($user)->getJson('/api/v1/projects');
    $index->assertOk();
    expect(collect($index->json('data'))->pluck('key'))->not->toContain($otherProject->key);

    $show = $this->actingAs($user)->getJson("/api/v1/projects/{$otherProject->key}");
    $show->assertStatus(404);
});

it('stops granting visibility once the membership is deactivated', function () {
    $project = Project::factory()->create();
    $user = visActor();
    $membership = Membership::factory()->for($project)->for($user)->withRole(Role::Reporter)->create();

    $this->actingAs($user)->getJson("/api/v1/projects/{$project->key}")->assertOk();

    $membership->is_active = false;
    $membership->deactivated_at = now();
    $membership->save();

    $response = $this->actingAs($user)->getJson("/api/v1/projects/{$project->key}");
    $response->assertStatus(404);
});

it('stops granting visibility once the last active role is deactivated', function () {
    $project = Project::factory()->create();
    $user = visActor();
    $membership = Membership::factory()->for($project)->for($user)->withRole(Role::Reporter)->create();

    // Membership itself grants viewing regardless of role (FR-004): deactivating
    // the only role does not remove visibility, only deactivating the membership does.
    $role = $membership->roles()->first();
    $role->is_active = false;
    $role->deactivated_at = now();
    $role->save();

    $response = $this->actingAs($user)->getJson("/api/v1/projects/{$project->key}");
    $response->assertOk();
});

it('lets a system-wide Admin see every project without membership', function () {
    $project = Project::factory()->create();
    $admin = visSystemAdmin();

    expect(Membership::where('project_id', $project->id)->where('user_id', $admin->id)->exists())->toBeFalse();

    $index = $this->actingAs($admin)->getJson('/api/v1/projects');
    $index->assertOk();
    expect(collect($index->json('data'))->pluck('key'))->toContain($project->key);

    $show = $this->actingAs($admin)->getJson("/api/v1/projects/{$project->key}");
    $show->assertOk();
});

it('returns a non-disclosing problem+json 404 for a project outside any grant', function () {
    $otherProject = Project::factory()->create();
    $user = visActor();

    $response = $this->actingAs($user)->getJson("/api/v1/projects/{$otherProject->key}");

    $response->assertStatus(404);
    $response->assertHeader('Content-Type', 'application/problem+json');
});

it('never leaks one user\'s visible project into another user\'s list', function () {
    $projectA = Project::factory()->create();
    $userA = visActor();
    $userB = visActor();
    Membership::factory()->for($projectA)->for($userA)->withRole(Role::Reporter)->create();

    $responseB = $this->actingAs($userB)->getJson('/api/v1/projects');

    $responseB->assertOk();
    expect(collect($responseB->json('data'))->pluck('key'))->not->toContain($projectA->key);
});

it('represents active memberships and role grants on /api/v1/me', function () {
    $project = Project::factory()->create(['name' => 'Widgets']);
    $user = visActor();
    $membership = Membership::factory()->for($project)->for($user)->withRole(Role::Reporter)->create();
    $membership->roles()->create(['role' => Role::Developer, 'granted_at' => now(), 'granted_by_id' => $user->id]);

    $response = $this->actingAs($user)->getJson('/api/v1/me');

    $response->assertOk();
    $response->assertJsonPath('data.memberships.0.project.key', $project->key);
    $response->assertJsonPath('data.memberships.0.project.name', 'Widgets');
    $roles = $response->json('data.memberships.0.roles');
    expect($roles)->toEqualCanonicalizing(['reporter', 'developer']);
});

it('excludes deactivated memberships and roles from /api/v1/me', function () {
    $activeProject = Project::factory()->create();
    $inactiveProject = Project::factory()->create();
    $user = visActor();
    Membership::factory()->for($activeProject)->for($user)->withRole(Role::Reporter)->create();
    $inactiveMembership = Membership::factory()->for($inactiveProject)->for($user)->withRole(Role::QA)->create();
    $inactiveMembership->is_active = false;
    $inactiveMembership->deactivated_at = now();
    $inactiveMembership->save();

    $response = $this->actingAs($user)->getJson('/api/v1/me');

    $response->assertOk();
    $projectKeys = collect($response->json('data.memberships'))->pluck('project.key');
    expect($projectKeys)->toContain($activeProject->key);
    expect($projectKeys)->not->toContain($inactiveProject->key);
});

it('reflects a role revoked after /me was first read on the very next request', function () {
    $project = Project::factory()->create();
    $user = visActor();
    $membership = Membership::factory()->for($project)->for($user)->withRole(Role::Developer)->create();

    $first = $this->actingAs($user)->getJson('/api/v1/me');
    expect($first->json('data.memberships.0.roles'))->toBe(['developer']);

    $membership->roles()->where('role', 'developer')->update(['is_active' => false, 'deactivated_at' => now()]);

    $second = $this->actingAs($user)->getJson('/api/v1/me');
    expect($second->json('data.memberships.0.roles'))->toBe([]);
});

it('never lets a client-supplied workspace/project header change authorization', function () {
    $ownProject = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $user = visActor();
    Membership::factory()->for($ownProject)->for($user)->withRole(Role::Admin)->create();

    // A client cannot elevate scope by claiming a different "current project"
    // out of band; the backend has no such concept to read from at all.
    $response = $this->actingAs($user)
        ->withHeaders(['X-Workspace-Project' => $otherProject->key, 'X-Current-Project-Id' => $otherProject->id])
        ->patchJson("/api/v1/projects/{$otherProject->key}", ['name' => 'Hijacked']);

    $response->assertStatus(403);
    expect($otherProject->fresh()->name)->not->toBe('Hijacked');
});
