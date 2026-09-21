<?php

use App\Enums\Role;
use App\Models\ActivityEvent;
use App\Models\Bug;
use App\Models\BugRelationship;
use App\Models\Membership;
use App\Models\Project;
use App\Models\TrackingValue;

it('generates a deterministic, invariant-respecting performance dataset', function () {
    $this->artisan('bugsense:seed-performance', ['--bugs' => 1000, '--projects' => 2])
        ->assertExitCode(0);

    expect(Project::where('key', 'like', 'PERF%')->count())->toBe(2);

    $bugCount = Bug::count();
    expect($bugCount)->toBeGreaterThan(900)->toBeLessThan(1100);

    // Every documented role is present for at least one project.
    foreach ([Role::Admin, Role::Reporter, Role::Developer, Role::QA] as $role) {
        expect(Membership::whereHas('roles', fn ($q) => $q->where('role', $role->value))->exists())
            ->toBeTrue("Expected at least one active {$role->value} membership.");
    }

    // Every documented TrackingValue kind is present, with distinct ranks
    // for the ranked kinds (priority/severity).
    foreach (['category', 'priority', 'severity', 'tag'] as $kind) {
        expect(TrackingValue::where('kind', $kind)->exists())->toBeTrue("Expected at least one {$kind} TrackingValue.");
    }
    $priorityRanks = TrackingValue::where('kind', 'priority')->where('project_id', Project::where('key', 'PERF1')->first()->id)->pluck('rank');
    expect($priorityRanks->unique()->count())->toBe($priorityRanks->count());

    // Unset classifications and unassigned Bugs are both represented.
    expect(Bug::whereNull('priority_id')->exists())->toBeTrue();
    expect(Bug::whereNull('assignee_membership_id')->exists())->toBeTrue();

    // A deterministic, findable marker Bug exists with the expected title.
    $marker = Bug::where('title', 'like', 'PERFMARK-PERF1-1:%')->first();
    expect($marker)->not->toBeNull();

    // Representative history and relationships exist, and every
    // relationship type the task requires is represented.
    expect(ActivityEvent::count())->toBeGreaterThan(0);
    expect(BugRelationship::where('relationship_type', 'related_to')->exists())->toBeTrue();
    expect(BugRelationship::where('relationship_type', 'blocks')->exists())->toBeTrue();
    expect(BugRelationship::where('relationship_type', 'duplicate_of')->exists())->toBeTrue();

    // Workflow-diverse statuses beyond the bulk Submitted/Review filler
    // exist, each only reachable through the real, already-tested Actions.
    foreach (['assigned', 'in_progress', 'closed'] as $status) {
        expect(Bug::where('status', $status)->exists())->toBeTrue("Expected at least one {$status} Bug.");
    }
});

it('refuses to run again without --fresh once a performance dataset exists', function () {
    $this->artisan('bugsense:seed-performance', ['--bugs' => 40, '--projects' => 1])->assertExitCode(0);

    $this->artisan('bugsense:seed-performance', ['--bugs' => 40, '--projects' => 1])->assertExitCode(1);
});

it('resets and regenerates an equivalent dataset with --fresh', function () {
    $this->artisan('bugsense:seed-performance', ['--bugs' => 200, '--projects' => 2])->assertExitCode(0);
    $firstBugCount = Bug::count();

    $this->artisan('bugsense:seed-performance', ['--bugs' => 200, '--projects' => 2, '--fresh' => true])
        ->assertExitCode(0);

    expect(Project::where('key', 'like', 'PERF%')->count())->toBe(2);
    expect(Bug::count())->toBe($firstBugCount);
});
