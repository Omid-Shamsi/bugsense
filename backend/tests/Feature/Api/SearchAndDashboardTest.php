<?php

use App\Actions\Bugs\AssignDeveloper;
use App\Actions\Bugs\BeginReview;
use App\Actions\Bugs\RecordFixedResolution;
use App\Actions\Bugs\ResumeWork;
use App\Actions\Bugs\StartWork;
use App\Actions\Bugs\VerifyResolution;
use App\Enums\QAVerificationDecision;
use App\Enums\Role;
use App\Models\Bug;
use App\Models\Membership;
use App\Models\Project;
use App\Models\TrackingValue;
use App\Models\User;

function sdAdmin(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Admin)->create();

    return $user;
}

function sdSystemAdmin(): User
{
    return User::factory()->systemAdmin()->create();
}

function sdReporter(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Reporter)->create();

    return $user;
}

function sdDeveloper(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::Developer)->create();

    return $user;
}

function sdQA(Project $project): User
{
    $user = User::factory()->create();
    Membership::factory()->for($project)->for($user)->withRole(Role::QA)->create();

    return $user;
}

function sdTracking(Project $project, string $kind, string $code, ?int $rank = null): TrackingValue
{
    return TrackingValue::factory()->create([
        'project_id' => $project->id,
        'kind' => $kind,
        'code' => $code,
        'name' => ucfirst($code),
        'rank' => $rank,
    ]);
}

function sdBug(Project $project, User $reporter, array $attributes = []): Bug
{
    return Bug::factory()->for($project)->for($reporter, 'reporter')->create($attributes);
}

// -- Permissions --------------------------------------------------------------------

it('applies visibility before search: an outsider never sees a foreign-project bug in results', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $outsider = sdReporter($projectB);
    $bug = sdBug($projectA, sdReporter($projectA), ['title' => 'Login is broken']);

    $response = $this->actingAs($outsider)->getJson('/api/v1/bugs?q=Login');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($bug->id);
});

it('does not disclose an exact hidden public ID to a non-member', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $outsider = sdReporter($projectB);
    $bug = sdBug($projectA, sdReporter($projectA));

    $response = $this->actingAs($outsider)->getJson("/api/v1/bugs?q={$bug->public_id}");

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('never leaks a cross-project bug through combined filters', function () {
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    $memberB = sdReporter($projectB);
    $bugA = sdBug($projectA, sdReporter($projectA), ['status' => 'submitted']);

    $response = $this->actingAs($memberB)->getJson('/api/v1/bugs?'.http_build_query(['status' => ['submitted']]));

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($bugA->id);
});

it('lets a system-wide Admin search across every project without membership', function () {
    $project = Project::factory()->create();
    $admin = sdSystemAdmin();
    $bug = sdBug($project, sdReporter($project), ['title' => 'System admin visible bug']);

    $response = $this->actingAs($admin)->getJson('/api/v1/bugs?q=System admin visible');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($bug->id);
});

// -- Search -----------------------------------------------------------------------

it('finds a bug by its exact accessible public ID', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $bug = sdBug($project, $reporter);
    sdBug($project, $reporter);

    $response = $this->actingAs($reporter)->getJson("/api/v1/bugs?q={$bug->public_id}");

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$bug->id]);
});

it('matches title text case-insensitively', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $bug = sdBug($project, $reporter, ['title' => 'Checkout Button Missing']);

    $response = $this->actingAs($reporter)->getJson('/api/v1/bugs?q=checkout button');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($bug->id);
});

it('matches description text case-insensitively', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $bug = sdBug($project, $reporter, ['title' => 'Unrelated title', 'description' => 'The SESSION token expires early.']);

    $response = $this->actingAs($reporter)->getJson('/api/v1/bugs?q=session token');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('id'))->toContain($bug->id);
});

it('returns an empty result for a non-matching search term', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    sdBug($project, $reporter, ['title' => 'Something else entirely']);

    $response = $this->actingAs($reporter)->getJson('/api/v1/bugs?q=zzz_no_match_zzz');

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('narrows text search results further with an additional filter', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $matching = sdBug($project, $reporter, ['title' => 'Timeout error', 'status' => 'review']);
    $wrongStatus = sdBug($project, $reporter, ['title' => 'Timeout error', 'status' => 'submitted']);

    $response = $this->actingAs($reporter)->getJson('/api/v1/bugs?'.http_build_query(['q' => 'Timeout', 'status' => ['review']]));

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($matching->id);
    expect($ids)->not->toContain($wrongStatus->id);
});

// -- Filters ------------------------------------------------------------------------

it('intersects different filter dimensions with AND', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $high = sdTracking($project, 'priority', 'high', 1);
    $low = sdTracking($project, 'priority', 'low', 2);
    $match = sdBug($project, $reporter, ['status' => 'review', 'priority_id' => $high->id]);
    $wrongStatus = sdBug($project, $reporter, ['status' => 'submitted', 'priority_id' => $high->id]);
    $wrongPriority = sdBug($project, $reporter, ['status' => 'review', 'priority_id' => $low->id]);

    $response = $this->actingAs($reporter)->getJson('/api/v1/bugs?'.http_build_query([
        'status' => ['review'], 'priority' => [$high->id],
    ]));

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($match->id);
    expect($ids)->not->toContain($wrongStatus->id);
    expect($ids)->not->toContain($wrongPriority->id);
});

it('matches any selected value within the same filter dimension (OR)', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $reviewBug = sdBug($project, $reporter, ['status' => 'review']);
    $assignedBug = sdBug($project, $reporter, ['status' => 'submitted']);
    $wrongStatus = sdBug($project, $reporter, ['status' => 'needs_information']);

    $response = $this->actingAs($reporter)->getJson('/api/v1/bugs?'.http_build_query([
        'status' => ['review', 'submitted'],
    ]));

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($reviewBug->id);
    expect($ids)->toContain($assignedBug->id);
    expect($ids)->not->toContain($wrongStatus->id);
});

it('filters by project scope using project keys', function () {
    $projectA = Project::factory()->create(['key' => 'ALPHA']);
    $projectB = Project::factory()->create();
    $admin = sdSystemAdmin();
    $bugA = sdBug($projectA, sdReporter($projectA));
    $bugB = sdBug($projectB, sdReporter($projectB));

    $response = $this->actingAs($admin)->getJson('/api/v1/bugs?'.http_build_query(['project' => ['ALPHA']]));

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($bugA->id);
    expect($ids)->not->toContain($bugB->id);
});

it('filters by reporter', function () {
    $project = Project::factory()->create();
    $reporterX = sdReporter($project);
    $reporterY = sdReporter($project);
    $bugX = sdBug($project, $reporterX);
    $bugY = sdBug($project, $reporterY);

    $response = $this->actingAs($reporterX)->getJson('/api/v1/bugs?'.http_build_query(['reporter' => [$reporterX->id]]));

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($bugX->id);
    expect($ids)->not->toContain($bugY->id);
});

it('filters by assignee, including the literal unassigned value', function () {
    $project = Project::factory()->create();
    $admin = sdAdmin($project);
    $reporter = sdReporter($project);
    $developer = sdDeveloper($project);
    $assignedBug = sdBug($project, $reporter, ['status' => 'review']);
    $assignedBug = (new AssignDeveloper())->handle($admin, $assignedBug, $developer->id)->fresh();
    $unassignedBug = sdBug($project, $reporter);

    $byUser = $this->actingAs($admin)->getJson('/api/v1/bugs?'.http_build_query(['assignee' => [$developer->id]]))->json('data');
    expect(collect($byUser)->pluck('id'))->toContain($assignedBug->id);
    expect(collect($byUser)->pluck('id'))->not->toContain($unassignedBug->id);

    $byUnassigned = $this->actingAs($admin)->getJson('/api/v1/bugs?'.http_build_query(['assignee' => ['unassigned']]))->json('data');
    expect(collect($byUnassigned)->pluck('id'))->toContain($unassignedBug->id);
    expect(collect($byUnassigned)->pluck('id'))->not->toContain($assignedBug->id);
});

it('filters by tag with OR-within-dimension semantics and no duplicate rows', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $urgent = sdTracking($project, 'tag', 'urgent');
    $mobile = sdTracking($project, 'tag', 'mobile');
    $bugBoth = sdBug($project, $reporter);
    $bugBoth->tags()->attach([$urgent->id, $mobile->id], ['added_by_id' => $reporter->id, 'added_at' => now()]);
    $bugNeither = sdBug($project, $reporter);

    $response = $this->actingAs($reporter)->getJson('/api/v1/bugs?'.http_build_query(['tag' => [$urgent->id, $mobile->id]]));

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids->filter(fn ($id) => $id === $bugBoth->id))->toHaveCount(1);
    expect($ids)->not->toContain($bugNeither->id);
});

// -- Sorting ------------------------------------------------------------------------

it('sorts by created_at in both directions with a stable public-ID tie-breaker', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $this->travelTo(now()->subDays(2));
    $first = sdBug($project, $reporter);
    $this->travelTo(now()->addDay());
    $second = sdBug($project, $reporter);
    $this->travelBack();

    $asc = $this->actingAs($reporter)->getJson('/api/v1/bugs?sort=created_at&direction=asc')->json('data');
    expect(collect($asc)->pluck('id')->all())->toBe([$first->id, $second->id]);

    $desc = $this->actingAs($reporter)->getJson('/api/v1/bugs?sort=created_at&direction=desc')->json('data');
    expect(collect($desc)->pluck('id')->all())->toBe([$second->id, $first->id]);
});

it('sorts by priority rank, keeping unset values last in ascending order', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $high = sdTracking($project, 'priority', 'high', 1);
    $low = sdTracking($project, 'priority', 'low', 2);
    $bugHigh = sdBug($project, $reporter, ['priority_id' => $high->id]);
    $bugLow = sdBug($project, $reporter, ['priority_id' => $low->id]);
    $bugUnset = sdBug($project, $reporter);

    $response = $this->actingAs($reporter)->getJson('/api/v1/bugs?sort=priority&direction=asc')->json('data');

    expect(collect($response)->pluck('id')->all())->toBe([$bugHigh->id, $bugLow->id, $bugUnset->id]);
});

it('sorts by priority rank, keeping unset values last in descending order too', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $high = sdTracking($project, 'priority', 'high', 1);
    $low = sdTracking($project, 'priority', 'low', 2);
    $bugHigh = sdBug($project, $reporter, ['priority_id' => $high->id]);
    $bugLow = sdBug($project, $reporter, ['priority_id' => $low->id]);
    $bugUnset = sdBug($project, $reporter);

    $response = $this->actingAs($reporter)->getJson('/api/v1/bugs?sort=priority&direction=desc')->json('data');

    expect(collect($response)->pluck('id')->all())->toBe([$bugLow->id, $bugHigh->id, $bugUnset->id]);
});

it('sorts by severity rank the same way as priority', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $critical = sdTracking($project, 'severity', 'critical', 1);
    $minor = sdTracking($project, 'severity', 'minor', 2);
    $bugCritical = sdBug($project, $reporter, ['severity_id' => $critical->id]);
    $bugMinor = sdBug($project, $reporter, ['severity_id' => $minor->id]);
    $bugUnset = sdBug($project, $reporter);

    $response = $this->actingAs($reporter)->getJson('/api/v1/bugs?sort=severity&direction=asc')->json('data');

    expect(collect($response)->pluck('id')->all())->toBe([$bugCritical->id, $bugMinor->id, $bugUnset->id]);
});

it('sorts by public_id in both directions', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $first = sdBug($project, $reporter);
    $second = sdBug($project, $reporter);

    $asc = $this->actingAs($reporter)->getJson('/api/v1/bugs?sort=public_id&direction=asc')->json('data');
    expect(collect($asc)->pluck('id')->all())->toBe([$first->id, $second->id]);

    $desc = $this->actingAs($reporter)->getJson('/api/v1/bugs?sort=public_id&direction=desc')->json('data');
    expect(collect($desc)->pluck('id')->all())->toBe([$second->id, $first->id]);
});

it('defaults to latest-update-first ordering', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $older = sdBug($project, $reporter);
    $this->travelTo(now()->addMinute());
    $newer = sdBug($project, $reporter);
    $this->travelBack();

    $response = $this->actingAs($reporter)->getJson('/api/v1/bugs')->json('data');

    expect(collect($response)->pluck('id')->all())->toBe([$newer->id, $older->id]);
});

// -- Pagination ---------------------------------------------------------------------

it('bounds page size to the documented maximum', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    Bug::factory()->for($project)->for($reporter, 'reporter')->count(3)->create();

    $response = $this->actingAs($reporter)->getJson('/api/v1/bugs?per_page=500');

    $response->assertOk();
    expect($response->json('meta.per_page'))->toBe(100);
});

it('paginates deterministically across pages with no duplicates, even under a tag filter', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $tag = sdTracking($project, 'tag', 'shared');
    $bugs = Bug::factory()->for($project)->for($reporter, 'reporter')->count(5)->create();
    foreach ($bugs as $bug) {
        $bug->tags()->attach($tag->id, ['added_by_id' => $reporter->id, 'added_at' => now()]);
    }

    $query = http_build_query(['tag' => [$tag->id], 'sort' => 'public_id', 'direction' => 'asc', 'per_page' => 2]);
    $page1 = $this->actingAs($reporter)->getJson("/api/v1/bugs?{$query}&page=1")->json('data');
    $page2 = $this->actingAs($reporter)->getJson("/api/v1/bugs?{$query}&page=2")->json('data');
    $page3 = $this->actingAs($reporter)->getJson("/api/v1/bugs?{$query}&page=3")->json('data');

    $allIds = collect($page1)->pluck('id')->merge(collect($page2)->pluck('id'))->merge(collect($page3)->pluck('id'));
    expect($allIds->count())->toBe(5);
    expect($allIds->unique()->count())->toBe(5);
});

// -- Dashboard ------------------------------------------------------------------------

it('reports the same total as the equivalently filtered bug list', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    Bug::factory()->for($project)->for($reporter, 'reporter')->count(4)->create(['status' => 'review']);
    Bug::factory()->for($project)->for($reporter, 'reporter')->count(2)->create(['status' => 'submitted']);

    $listTotal = $this->actingAs($reporter)->getJson('/api/v1/bugs?'.http_build_query(['status' => ['review']]))->json('meta.total');
    $dashboard = $this->actingAs($reporter)->getJson('/api/v1/dashboards/summary?'.http_build_query(['status' => ['review']]))->json('data');

    expect($dashboard['counts']['total'])->toBe($listTotal);
    expect($listTotal)->toBe(4);
});

it('breaks bugs down by status-derived counts correctly', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'submitted']);
    Bug::factory()->for($project)->for($reporter, 'reporter')->count(2)->create(['status' => 'review']);
    Bug::factory()->for($project)->for($reporter, 'reporter')->create(['status' => 'closed']);

    $response = $this->actingAs($reporter)->getJson('/api/v1/dashboards/summary');

    $counts = $response->json('data.counts');
    expect($counts['total'])->toBe(4);
    expect($counts['closed'])->toBe(1);
    expect($counts['open'])->toBe(3);
    expect($counts['awaiting_review'])->toBe(3);
});

it('breaks bugs down by severity, keeping an explicit unset bucket', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    $critical = sdTracking($project, 'severity', 'critical', 1);
    Bug::factory()->for($project)->for($reporter, 'reporter')->create(['severity_id' => $critical->id]);
    Bug::factory()->for($project)->for($reporter, 'reporter')->count(2)->create();

    $response = $this->actingAs($reporter)->getJson('/api/v1/dashboards/summary');

    $breakdown = collect($response->json('data.breakdowns.severity'))->keyBy(fn ($row) => $row['id'] ?? 'unset');
    expect($breakdown['unset']['count'])->toBe(2);
    expect($breakdown[$critical->id]['count'])->toBe(1);
});

it('breaks bugs down by Developer, keeping an explicit unassigned bucket', function () {
    $project = Project::factory()->create();
    $admin = sdAdmin($project);
    $reporter = sdReporter($project);
    $developer = sdDeveloper($project);
    $assigned = sdBug($project, $reporter, ['status' => 'review']);
    (new AssignDeveloper())->handle($admin, $assigned, $developer->id);
    sdBug($project, $reporter);

    $response = $this->actingAs($reporter)->getJson('/api/v1/dashboards/summary');

    $breakdown = collect($response->json('data.breakdowns.developer'))->keyBy(fn ($row) => $row['id'] ?? 'unassigned');
    expect($breakdown['unassigned']['count'])->toBe(1);
    expect($breakdown[$developer->id]['count'])->toBe(1);
});

it('returns zero counts and no crash for an impossible filter combination', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    sdBug($project, $reporter, ['status' => 'submitted']);

    $response = $this->actingAs($reporter)->getJson('/api/v1/dashboards/summary?'.http_build_query(['status' => ['closed']]));

    $response->assertOk();
    expect($response->json('data.counts.total'))->toBe(0);
    expect($response->json('data.average_resolution_time.sample_count'))->toBe(0);
    expect($response->json('data.average_resolution_time.milliseconds'))->toBeNull();
});

// -- Resolution time ------------------------------------------------------------------

it('includes a QA Verification bug with a recorded resolution and computes the exact elapsed duration', function () {
    $project = Project::factory()->create();
    $admin = sdAdmin($project);
    $reporter = sdReporter($project);
    $developer = sdDeveloper($project);

    $created = now()->subHours(4);
    $this->travelTo($created);
    $bug = sdBug($project, $reporter);

    $this->travelTo($created->copy()->addHours(4)->addMinutes(30));
    $bug = (new BeginReview())->handle($admin, $bug)->fresh();
    $bug = (new AssignDeveloper())->handle($admin, $bug, $developer->id)->fresh();
    $bug = (new StartWork())->handle($developer, $bug)->fresh();
    (new RecordFixedResolution())->handle($developer, $bug, 'Fixed it.', 'Verify it.');
    $this->travelBack();

    $response = $this->actingAs($reporter)->getJson('/api/v1/dashboards/summary');

    expect($response->json('data.average_resolution_time.sample_count'))->toBe(1);
    expect($response->json('data.average_resolution_time.milliseconds'))->toBe(4 * 3600 * 1000 + 30 * 60 * 1000);
});

it('excludes a bug with no recorded resolution event from the sample', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);
    sdBug($project, $reporter, ['status' => 'in_progress']);

    $response = $this->actingAs($reporter)->getJson('/api/v1/dashboards/summary');

    expect($response->json('data.average_resolution_time.sample_count'))->toBe(0);
    expect($response->json('data.average_resolution_time.milliseconds'))->toBeNull();
});

it('excludes a currently Reopened bug from the resolution-time sample until it resolves again', function () {
    $project = Project::factory()->create();
    $admin = sdAdmin($project);
    $reporter = sdReporter($project);
    $developer = sdDeveloper($project);
    $qa = sdQA($project);

    $bug = sdBug($project, $reporter);
    $bug = (new BeginReview())->handle($admin, $bug)->fresh();
    $bug = (new AssignDeveloper())->handle($admin, $bug, $developer->id)->fresh();
    $bug = (new StartWork())->handle($developer, $bug)->fresh();
    $bug = (new RecordFixedResolution())->handle($developer, $bug, 'Fixed it.', 'Verify it.')->fresh();
    $bug = (new VerifyResolution())->handle($qa, $bug, QAVerificationDecision::Rejected, 'Not actually fixed.')->fresh();

    expect($bug->status->value)->toBe('reopened');

    $response = $this->actingAs($reporter)->getJson('/api/v1/dashboards/summary');
    expect($response->json('data.average_resolution_time.sample_count'))->toBe(0);
    expect($response->json('data.counts.reopened'))->toBe(1);

    // Resolved again: the sample reappears, measured from the ORIGINAL
    // creation time (A-03 — reopened dwell time is included, not excluded).
    $bug = (new ResumeWork())->handle($developer, $bug)->fresh();
    (new RecordFixedResolution())->handle($developer, $bug, 'Actually fixed now.', 'Verify again.');

    $response = $this->actingAs($reporter)->getJson('/api/v1/dashboards/summary');
    expect($response->json('data.average_resolution_time.sample_count'))->toBe(1);
    expect($response->json('data.average_resolution_time.milliseconds'))->toBeGreaterThanOrEqual(0);
});

it('keeps a Closed bug in the resolution-time sample', function () {
    $project = Project::factory()->create();
    $admin = sdAdmin($project);
    $reporter = sdReporter($project);
    $developer = sdDeveloper($project);
    $qa = sdQA($project);

    $bug = sdBug($project, $reporter);
    $bug = (new BeginReview())->handle($admin, $bug)->fresh();
    $bug = (new AssignDeveloper())->handle($admin, $bug, $developer->id)->fresh();
    $bug = (new StartWork())->handle($developer, $bug)->fresh();
    $bug = (new RecordFixedResolution())->handle($developer, $bug, 'Fixed it.', 'Verify it.')->fresh();
    (new VerifyResolution())->handle($qa, $bug, QAVerificationDecision::Approved, 'Confirmed.');

    $response = $this->actingAs($reporter)->getJson('/api/v1/dashboards/summary');

    expect($response->json('data.average_resolution_time.sample_count'))->toBe(1);
    expect($response->json('data.counts.closed'))->toBe(1);
});

it('reports the defined zero-sample representation, never a zero-duration average', function () {
    $project = Project::factory()->create();
    $reporter = sdReporter($project);

    $response = $this->actingAs($reporter)->getJson('/api/v1/dashboards/summary');

    expect($response->json('data.average_resolution_time.sample_count'))->toBe(0);
    expect($response->json('data.average_resolution_time'))->toHaveKey('milliseconds');
    expect($response->json('data.average_resolution_time.milliseconds'))->toBeNull();
});
