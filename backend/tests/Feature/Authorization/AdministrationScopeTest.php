<?php

use App\Enums\Role;
use App\Models\Membership;
use App\Models\Project;
use App\Models\User;

function systemAdmin(): User
{
    return User::factory()->create(['is_system_admin' => true]);
}

function projectAdminOf(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Admin)->create();

    return $user;
}

function memberOf(Project $project, Role $role = Role::Reporter): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole($role)->create();

    return $user;
}

it('lets a system-wide Admin create a project', function () {
    $actor = systemAdmin();

    $response = $this->actingAs($actor)->postJson('/api/v1/projects', [
        'key' => 'ALPHA',
        'name' => 'Alpha Project',
    ]);

    $response->assertCreated();
    expect(Project::where('key', 'ALPHA')->exists())->toBeTrue();
});

it('denies project creation to a project-scoped Admin', function () {
    $existing = Project::factory()->create();
    $actor = projectAdminOf($existing);

    $response = $this->actingAs($actor)->postJson('/api/v1/projects', [
        'key' => 'BETA',
        'name' => 'Beta Project',
    ]);

    $response->assertStatus(403);
    expect(Project::where('key', 'BETA')->exists())->toBeFalse();
});

it('denies project creation to an ordinary member', function () {
    $project = Project::factory()->create();
    $actor = memberOf($project, Role::Reporter);

    $response = $this->actingAs($actor)->postJson('/api/v1/projects', [
        'key' => 'GAMMA',
        'name' => 'Gamma Project',
    ]);

    $response->assertStatus(403);
});

it('lets a project-scoped Admin update their own project', function () {
    $project = Project::factory()->create(['name' => 'Old Name']);
    $actor = projectAdminOf($project);

    $response = $this->actingAs($actor)->patchJson("/api/v1/projects/{$project->key}", [
        'name' => 'New Name',
    ]);

    $response->assertOk();
    expect($project->fresh()->name)->toBe('New Name');
});

it('denies a project-scoped Admin from updating a different project', function () {
    $ownProject = Project::factory()->create();
    $otherProject = Project::factory()->create(['name' => 'Untouched']);
    $actor = projectAdminOf($ownProject);

    $response = $this->actingAs($actor)->patchJson("/api/v1/projects/{$otherProject->key}", [
        'name' => 'Hijacked',
    ]);

    $response->assertStatus(403);
    expect($otherProject->fresh()->name)->toBe('Untouched');
});

it('restricts system-wide user management to system-wide Admins', function () {
    $project = Project::factory()->create();
    $projectAdmin = projectAdminOf($project);

    $response = $this->actingAs($projectAdmin)->postJson('/api/v1/users', [
        'email' => 'new-user@example.com',
        'display_name' => 'New User',
        'password' => 'a-very-long-password',
    ]);

    $response->assertStatus(403);
    expect(User::where('email', 'new-user@example.com')->exists())->toBeFalse();
});

it('lets a system-wide Admin create a system user', function () {
    $actor = systemAdmin();

    $response = $this->actingAs($actor)->postJson('/api/v1/users', [
        'email' => 'new-user@example.com',
        'display_name' => 'New User',
        'password' => 'a-very-long-password',
    ]);

    $response->assertCreated();
    expect(User::where('email', 'new-user@example.com')->exists())->toBeTrue();
});

it('lets a project-scoped Admin grant an ordinary role within their own project', function () {
    $project = Project::factory()->create();
    $actor = projectAdminOf($project);
    $newMember = User::factory()->create();

    $response = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->key}/memberships", [
        'user_id' => $newMember->id,
        'roles' => ['reporter', 'developer'],
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.roles', ['reporter', 'developer']);
});

it('denies a project-scoped Admin from granting the Admin role', function () {
    $project = Project::factory()->create();
    $actor = projectAdminOf($project);
    $newMember = User::factory()->create();

    $response = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->key}/memberships", [
        'user_id' => $newMember->id,
        'roles' => ['admin'],
    ]);

    $response->assertStatus(403);
    expect(Membership::where('project_id', $project->id)->where('user_id', $newMember->id)->exists())->toBeFalse();
});

it('denies an ordinary member from granting roles even in their own project', function () {
    $project = Project::factory()->create();
    $actor = memberOf($project, Role::Developer);
    $newMember = User::factory()->create();

    $response = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->key}/memberships", [
        'user_id' => $newMember->id,
        'roles' => ['reporter'],
    ]);

    $response->assertStatus(403);
});

it('lets a system-wide Admin grant the Admin role', function () {
    $project = Project::factory()->create();
    $actor = systemAdmin();
    $newMember = User::factory()->create();

    $response = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->key}/memberships", [
        'user_id' => $newMember->id,
        'roles' => ['admin'],
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.roles', ['admin']);
});

it('denies a project-scoped Admin from adding the Admin role via membership update', function () {
    $project = Project::factory()->create();
    $actor = projectAdminOf($project);
    $membership = Membership::factory()->for($project)->withRole(Role::Reporter)->create();

    $response = $this->actingAs($actor)->patchJson("/api/v1/memberships/{$membership->id}", [
        'roles' => ['reporter', 'admin'],
    ]);

    $response->assertStatus(403);
    expect($membership->fresh()->activeRoles()->pluck('role')->map(fn ($r) => $r->value)->all())->toBe(['reporter']);
});

it('denies a project-scoped Admin from managing another project\'s memberships', function () {
    $ownProject = Project::factory()->create();
    $otherProject = Project::factory()->create();
    $actor = projectAdminOf($ownProject);
    $target = User::factory()->create();

    $response = $this->actingAs($actor)->postJson("/api/v1/projects/{$otherProject->key}/memberships", [
        'user_id' => $target->id,
        'roles' => ['reporter'],
    ]);

    $response->assertStatus(403);
});

it('does not let a role granted in one project authorize actions in another project', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $actor = projectAdminOf($projectA);

    $response = $this->actingAs($actor)->postJson("/api/v1/projects/{$projectB->key}/tracking-values", [
        'kind' => 'category',
        'code' => 'ui',
        'name' => 'UI',
    ]);

    $response->assertStatus(403);
});

it('lets a project-scoped Admin manage tracking values in their own project', function () {
    $project = Project::factory()->create();
    $actor = projectAdminOf($project);

    $response = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->key}/tracking-values", [
        'kind' => 'category',
        'code' => 'ui',
        'name' => 'UI',
    ]);

    $response->assertCreated();
});

it('denies tracking-value creation to an ordinary member', function () {
    $project = Project::factory()->create();
    $actor = memberOf($project, Role::QA);

    $response = $this->actingAs($actor)->postJson("/api/v1/projects/{$project->key}/tracking-values", [
        'kind' => 'category',
        'code' => 'ui',
        'name' => 'UI',
    ]);

    $response->assertStatus(403);
});

it('lets a system-wide Admin manage any project without membership', function () {
    $project = Project::factory()->create();
    $actor = systemAdmin();

    expect(Membership::where('project_id', $project->id)->where('user_id', $actor->id)->exists())->toBeFalse();

    $response = $this->actingAs($actor)->patchJson("/api/v1/projects/{$project->key}", [
        'name' => 'Renamed by system admin',
    ]);

    $response->assertOk();
});
