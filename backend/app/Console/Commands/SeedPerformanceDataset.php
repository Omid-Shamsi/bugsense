<?php

namespace App\Console\Commands;

use App\Actions\Bugs\AssignDeveloper;
use App\Actions\Bugs\BeginReview;
use App\Actions\Bugs\RecordFixedResolution;
use App\Actions\Bugs\RecordNonFixResolution;
use App\Actions\Bugs\RequestInformation;
use App\Actions\Bugs\StartWork;
use App\Actions\Bugs\VerifyResolution;
use App\Actions\Relationships\CreateBugRelationship;
use App\Enums\BugRelationshipType;
use App\Enums\QAVerificationDecision;
use App\Enums\ResolutionOutcome;
use App\Enums\Role;
use App\Models\Bug;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Project;
use App\Models\TrackingValue;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local-only deterministic performance dataset for T059/T062 (FR-035–FR-044,
 * SC-005/SC-006): 5 projects, 10,000 Bugs, and enough of every classification
 * dimension, relationship type, and workflow status to exercise every
 * BugQuery/DashboardQuery code path against a realistic volume.
 *
 * Two-tier generation, both fully within existing invariants:
 *  - "Tier A" (~97% of Bugs): bulk-inserted, Submitted/Review only — these
 *    statuses need no assignee or resolution rows, so they are safe to
 *    create directly without going through the real Actions, which keeps
 *    10,000 rows fast to generate.
 *  - "Tier B" (~3% of Bugs): driven through the real, already-tested
 *    Actions (BeginReview, AssignDeveloper, StartWork,
 *    RecordFixedResolution, RecordNonFixResolution, VerifyResolution,
 *    RequestInformation, CreateBugRelationship) so every deeper status,
 *    ResolutionAttempt, QAVerificationResult, and relationship this
 *    produces is exactly as valid as one created through the API.
 *
 * Never runs automatically — this is an explicit, opt-in Artisan command,
 * not wired into any request path, scheduler, or seeder autoload.
 */
class SeedPerformanceDataset extends Command
{
    protected $signature = 'bugsense:seed-performance
        {--bugs=10000 : Total number of Bugs to generate}
        {--projects=5 : Number of projects to generate}
        {--fresh : Delete any previously generated performance dataset first}';

    protected $description = 'Generate a deterministic local performance dataset (default: 5 projects / 10,000 Bugs) for search/dashboard measurement.';

    private const SEED = 20260101;

    private const PROJECT_KEY_PREFIX = 'PERF';

    private const USER_EMAIL_DOMAIN = 'perf.bugsense.test';

    private const MARKER_TOKEN = 'PERFMARK';

    private const SYMPTOMS = [
        'Login fails after password reset', 'Checkout button unresponsive on mobile',
        'Dashboard totals mismatch after filter change', 'Upload spinner never resolves',
        'Session expires while editing a report', 'Search results missing recent items',
        'Notification banner overlaps navigation', 'Export produces a truncated file',
        'Timezone offset wrong in timestamps', 'Pagination skips the last page',
        'Tag filter clears silently on refresh', 'Attachment preview shows a blank frame',
        'Sort order resets after navigating back', 'Assignee dropdown missing active Developers',
        'Priority badge color inconsistent with rank', 'Duplicate link points at the wrong report',
        'Reopened report loses its assignee', 'QA queue omits an eligible bug',
        'Activity timeline order is unstable', 'Relationship panel double-counts a link',
    ];

    private const ENVIRONMENTS = ['Chrome 128 / Windows 11', 'Safari 17 / macOS 14', 'Firefox 130 / Ubuntu 24.04', 'Edge 128 / Windows 10'];

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->components->error('Refusing to run bugsense:seed-performance in the production environment.');

            return self::FAILURE;
        }

        $projectCount = max(1, (int) $this->option('projects'));
        $bugCount = max(1, (int) $this->option('bugs'));

        mt_srand(self::SEED);
        fake()->seed(self::SEED);

        if ($this->option('fresh')) {
            $this->resetPreviousDataset();
        } elseif (Project::where('key', 'like', self::PROJECT_KEY_PREFIX.'%')->exists()) {
            $this->components->error('A performance dataset already exists. Re-run with --fresh to replace it.');

            return self::FAILURE;
        }

        $startedAt = microtime(true);

        $projects = $this->createProjects($projectCount);

        $tierBPerProject = (int) floor($bugCount * 0.03 / $projectCount);
        $tierAPerProject = (int) floor(($bugCount - $tierBPerProject * $projectCount) / $projectCount);

        $markers = [];
        $totalCreated = 0;

        foreach ($projects as $project) {
            $this->components->task("Seeding {$project->key}", function () use ($project, $tierAPerProject, $tierBPerProject, &$markers, &$totalCreated) {
                $people = $this->createUsersAndMemberships($project);
                $tracking = $this->createTrackingValues($project);

                $tierABugIds = $this->createTierABugs($project, $tierAPerProject, $people, $tracking, $markers);
                $this->createTierBBugs($project, $tierBPerProject, $people, $tracking, $markers);
                $this->createRelationships($project, $tierABugIds);

                $totalCreated += $tierAPerProject + $tierBPerProject;

                return true;
            });
        }

        $elapsed = round(microtime(true) - $startedAt, 2);

        $this->newLine();
        $this->components->twoColumnDetail('Projects', (string) $projectCount);
        $this->components->twoColumnDetail('Bugs created', (string) $totalCreated);
        $this->components->twoColumnDetail('Elapsed', "{$elapsed}s");
        $this->newLine();
        $this->line('Marker bugs (deterministic, for search/target-finding evidence):');
        foreach ($markers as $label => $publicId) {
            $this->line("  {$label} => {$publicId}");
        }

        return self::SUCCESS;
    }

    // -- Reset -------------------------------------------------------------------------

    private function resetPreviousDataset(): void
    {
        DB::transaction(function () {
            $projectIds = Project::where('key', 'like', self::PROJECT_KEY_PREFIX.'%')->pluck('id');

            if ($projectIds->isEmpty()) {
                return;
            }

            $bugIds = Bug::whereIn('project_id', $projectIds)->pluck('id');

            DB::table('activity_events')->whereIn('project_id', $projectIds)->delete();
            DB::table('qa_verification_results')->whereIn(
                'resolution_attempt_id',
                DB::table('resolution_attempts')->whereIn('bug_id', $bugIds)->select('id'),
            )->delete();
            Bug::whereIn('id', $bugIds)->update(['active_resolution_attempt_id' => null]);
            DB::table('resolution_attempts')->whereIn('bug_id', $bugIds)->delete();
            DB::table('bug_relationships')->whereIn('project_id', $projectIds)->delete();
            DB::table('bug_tags')->whereIn('bug_id', $bugIds)->delete();
            DB::table('information_requests')->whereIn('bug_id', $bugIds)->delete();
            DB::table('progress_updates')->whereIn('bug_id', $bugIds)->delete();
            Bug::whereIn('id', $bugIds)->delete();
            DB::table('membership_roles')->whereIn(
                'membership_id',
                DB::table('memberships')->whereIn('project_id', $projectIds)->select('id'),
            )->delete();
            $userIds = DB::table('memberships')->whereIn('project_id', $projectIds)->pluck('user_id')->unique();
            DB::table('memberships')->whereIn('project_id', $projectIds)->delete();
            DB::table('tracking_values')->whereIn('project_id', $projectIds)->delete();
            Project::whereIn('id', $projectIds)->delete();

            // Only ever deletes users under the deterministic perf email
            // domain — never a real/demo account, even one incidentally
            // reused via a shared UUID collision (impossible) or a stale FK.
            User::whereIn('id', $userIds)->where('email', 'like', '%@'.self::USER_EMAIL_DOMAIN)->delete();
        });
    }

    // -- Projects / people / tracking values --------------------------------------------

    /**
     * @return list<Project>
     */
    private function createProjects(int $count): array
    {
        $projects = [];

        for ($i = 1; $i <= $count; $i++) {
            $projects[] = Project::create([
                'key' => self::PROJECT_KEY_PREFIX.$i,
                'name' => "Performance Project {$i}",
                'description' => 'Deterministic dataset for search/dashboard performance measurement (T059).',
            ]);
        }

        return $projects;
    }

    /**
     * @return array{admin_ids: list<string>, reporter_ids: list<string>, developer_ids: list<string>, developer_membership_ids: list<string>, qa_ids: list<string>}
     */
    private function createUsersAndMemberships(Project $project): array
    {
        $roles = [
            'admin' => ['count' => 1, 'role' => Role::Admin],
            'reporter' => ['count' => 3, 'role' => Role::Reporter],
            'developer' => ['count' => 4, 'role' => Role::Developer],
            'qa' => ['count' => 2, 'role' => Role::QA],
        ];

        $result = ['admin_ids' => [], 'reporter_ids' => [], 'developer_ids' => [], 'developer_membership_ids' => [], 'qa_ids' => []];

        foreach ($roles as $label => $spec) {
            for ($n = 1; $n <= $spec['count']; $n++) {
                $email = 'perf.'.strtolower($project->key).".{$label}.{$n}@".self::USER_EMAIL_DOMAIN;

                $user = User::create([
                    'display_name' => 'Perf '.ucfirst($label)." {$project->key}-{$n}",
                    'email' => $email,
                    'password' => 'unused-performance-account',
                ]);

                $membership = Membership::create([
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'joined_at' => now()->subDays(180),
                ]);

                MembershipRole::create([
                    'membership_id' => $membership->id,
                    'role' => $spec['role'],
                    'granted_at' => now()->subDays(180),
                    'granted_by_id' => $user->id,
                ]);

                $result["{$label}_ids"][] = $user->id;

                if ($label === 'developer') {
                    $result['developer_membership_ids'][] = $membership->id;
                }
            }
        }

        return $result;
    }

    /**
     * @return array{categories: list<string>, priorities: list<string>, severities: list<string>, tags: list<string>}
     */
    private function createTrackingValues(Project $project): array
    {
        $categories = ['ui', 'api', 'data', 'performance', 'auth'];
        $priorities = ['low', 'medium', 'high', 'urgent'];
        $severities = ['minor', 'moderate', 'major', 'critical'];
        $tags = ['regression', 'mobile', 'desktop', 'flaky', 'customer-reported', 'security'];

        $ids = ['categories' => [], 'priorities' => [], 'severities' => [], 'tags' => []];

        foreach ($categories as $code) {
            $ids['categories'][] = TrackingValue::create([
                'project_id' => $project->id, 'kind' => 'category', 'code' => $code, 'name' => ucfirst($code),
            ])->id;
        }

        foreach ($priorities as $rank => $code) {
            $ids['priorities'][] = TrackingValue::create([
                'project_id' => $project->id, 'kind' => 'priority', 'code' => $code, 'name' => ucfirst($code), 'rank' => $rank + 1,
            ])->id;
        }

        foreach ($severities as $rank => $code) {
            $ids['severities'][] = TrackingValue::create([
                'project_id' => $project->id, 'kind' => 'severity', 'code' => $code, 'name' => ucfirst($code), 'rank' => $rank + 1,
            ])->id;
        }

        foreach ($tags as $code) {
            $ids['tags'][] = TrackingValue::create([
                'project_id' => $project->id, 'kind' => 'tag', 'code' => $code, 'name' => ucfirst($code),
            ])->id;
        }

        return $ids;
    }

    // -- Tier A: bulk-inserted Submitted/Review filler -----------------------------------

    /**
     * @param  array<string, mixed>  $people
     * @param  array<string, mixed>  $tracking
     * @param  array<string, string>  $markers
     * @return list<string>
     */
    private function createTierABugs(Project $project, int $count, array $people, array $tracking, array &$markers): array
    {
        $projectNumber = (int) substr($project->key, strlen(self::PROJECT_KEY_PREFIX));
        $bugRows = [];
        $tagRows = [];
        $eventRows = [];
        $bugIds = [];
        $now = now();

        for ($i = 1; $i <= $count; $i++) {
            $id = (string) Str::uuid();
            $bugIds[] = $id;

            $isMarker = $i <= 4;
            $reporterOrdinal = (($i - 1) % count($people['reporter_ids'])) + 1;
            $reporterId = $people['reporter_ids'][$reporterOrdinal - 1];
            $reporterDisplay = "Perf Reporter {$project->key}-{$reporterOrdinal}";
            $symptom = self::SYMPTOMS[($projectNumber * 37 + $i) % count(self::SYMPTOMS)];
            $status = ($i % 5 === 0) ? 'review' : 'submitted';

            $hasCategory = $i % 10 !== 0;
            $hasPriority = $i % 7 !== 0;
            $hasSeverity = $i % 11 !== 0;

            $title = $isMarker
                ? self::MARKER_TOKEN.'-'.$project->key.'-'.$i.': '.$symptom
                : "{$symptom} (report #{$i})";

            $createdAt = $now->copy()->subDays(($i * 13) % 180)->subMinutes($i % 1440);
            // Bug::insert()/DB::table()->insert() are raw query-builder
            // calls, bypassing Eloquent's datetime casting — dates must
            // already be strings.
            $createdAtSql = $createdAt->format('Y-m-d H:i:s');

            $bugRows[] = [
                'id' => $id,
                'project_id' => $project->id,
                'reporter_id' => $reporterId,
                'title' => $title,
                'description' => "Observed on {$this->environmentFor($i)}. Steps: open the affected area, reproduce {$symptom}, and confirm against project {$project->key}.",
                'steps_to_reproduce' => "1. Sign in.\n2. Navigate to the affected screen.\n3. Trigger: {$symptom}.",
                'expected_result' => 'The action completes without error.',
                'actual_result' => $symptom,
                'environment' => $this->environmentFor($i),
                'status' => $status,
                'category_id' => $hasCategory ? $tracking['categories'][$i % count($tracking['categories'])] : null,
                'priority_id' => $hasPriority ? $tracking['priorities'][$i % count($tracking['priorities'])] : null,
                'severity_id' => $hasSeverity ? $tracking['severities'][$i % count($tracking['severities'])] : null,
                'created_at' => $createdAtSql,
                'updated_at' => $createdAtSql,
            ];

            if ($isMarker) {
                $markers[self::MARKER_TOKEN.'-'.$project->key.'-'.$i] = $title;
            }

            $eventRows[] = [
                'event_scope' => 'bug',
                'project_id' => $project->id,
                'bug_id' => $id,
                'sequence' => 1,
                'event_type' => 'bug.created',
                'actor_id' => $reporterId,
                'actor_display' => $reporterDisplay,
                'occurred_at' => $createdAtSql,
                'command_id' => (string) Str::uuid(),
                'before_data' => '{}',
                'after_data' => json_encode(['title' => $title, 'status' => $status]),
                'reason_or_result' => null,
            ];

            if ($i % 3 === 0) {
                $tagRows[] = [
                    'bug_id' => $id,
                    'tracking_value_id' => $tracking['tags'][$i % count($tracking['tags'])],
                    'added_by_id' => $reporterId,
                    'added_at' => $createdAtSql,
                ];
            }
            if ($i % 8 === 0) {
                $tagRows[] = [
                    'bug_id' => $id,
                    'tracking_value_id' => $tracking['tags'][($i + 2) % count($tracking['tags'])],
                    'added_by_id' => $reporterId,
                    'added_at' => $createdAtSql,
                ];
            }
        }

        foreach (array_chunk($bugRows, 500) as $chunk) {
            Bug::insert($chunk);
        }
        foreach (array_chunk($eventRows, 500) as $chunk) {
            DB::table('activity_events')->insert($chunk);
        }
        foreach (array_chunk($tagRows, 500) as $chunk) {
            DB::table('bug_tags')->insert($chunk);
        }

        return $bugIds;
    }

    private function environmentFor(int $i): string
    {
        return self::ENVIRONMENTS[$i % count(self::ENVIRONMENTS)];
    }

    // -- Tier B: real-Action-driven workflow diversity -----------------------------------

    /**
     * @param  array<string, mixed>  $people
     * @param  array<string, mixed>  $tracking
     * @param  array<string, string>  $markers
     */
    private function createTierBBugs(Project $project, int $count, array $people, array $tracking, array &$markers): void
    {
        $admin = User::find($people['admin_ids'][0]);
        $qa = User::find($people['qa_ids'][0]);

        // Recipe weights sum to 1.0 of $count; every recipe reuses the same
        // real Actions the API uses, so every resulting status/attempt/QA
        // result/relationship is exactly as valid as one made through the UI.
        $recipeCounts = [
            'in_progress' => (int) round($count * 0.20),
            'assigned' => (int) round($count * 0.15),
            'needs_information' => (int) round($count * 0.10),
            'closed_fixed' => (int) round($count * 0.25),
            'reopened' => (int) round($count * 0.12),
            'closed_wont_fix' => (int) round($count * 0.08),
            'closed_cannot_reproduce' => (int) round($count * 0.06),
            'closed_duplicate' => (int) round($count * 0.04),
        ];

        // The first closed_duplicate iteration only ever creates the
        // "original" bug (see below) — a count of exactly 1 would produce
        // no actual duplicate_of relationship at all, so round up to the
        // smallest count that can form a real pair.
        if ($recipeCounts['closed_duplicate'] === 1) {
            $recipeCounts['closed_duplicate'] = 2;
        }

        $created = 0;
        $originalForDuplicates = null;

        foreach ($recipeCounts as $recipe => $recipeCount) {
            for ($n = 1; $n <= $recipeCount; $n++) {
                $created++;
                $reporter = User::find($people['reporter_ids'][$created % count($people['reporter_ids'])]);
                $developerId = $people['developer_ids'][$created % count($people['developer_ids'])];

                $bug = Bug::factory()->create([
                    'project_id' => $project->id,
                    'reporter_id' => $reporter->id,
                    'title' => "PERFMARK-WF-{$project->key}-{$recipe}-{$n}: workflow sample",
                    'category_id' => $tracking['categories'][$created % count($tracking['categories'])],
                    'priority_id' => $tracking['priorities'][$created % count($tracking['priorities'])],
                    'severity_id' => $tracking['severities'][$created % count($tracking['severities'])],
                ]);

                $bug = (new BeginReview())->handle($admin, $bug)->fresh();

                if ($recipe === 'needs_information') {
                    (new RequestInformation())->handle($admin, $bug, 'Please attach reproduction steps and a screenshot.');

                    continue;
                }

                $bug = (new AssignDeveloper())->handle($admin, $bug, $developerId)->fresh();

                if ($recipe === 'assigned') {
                    continue;
                }

                $bug = (new StartWork())->handle(User::find($developerId), $bug)->fresh();

                if ($recipe === 'in_progress') {
                    continue;
                }

                if ($recipe === 'closed_fixed' || $recipe === 'reopened') {
                    $bug = (new RecordFixedResolution())->handle(User::find($developerId), $bug, 'Applied the fix and verified locally.', 'Reproduce the original steps and confirm the issue no longer occurs.')->fresh();
                    $decision = $recipe === 'reopened' ? QAVerificationDecision::Rejected : QAVerificationDecision::Approved;
                    (new VerifyResolution())->handle($qa, $bug, $decision, $recipe === 'reopened' ? 'Still reproduces; sending back.' : 'Confirmed fixed.');

                    continue;
                }

                if ($recipe === 'closed_wont_fix') {
                    $bug = (new RecordNonFixResolution())->handle($admin, $bug, ResolutionOutcome::WontFix, 'Working as designed.', [
                        'decision_rationale' => 'Matches the documented behavior for this project.',
                    ])->fresh();
                    (new VerifyResolution())->handle($qa, $bug, QAVerificationDecision::Approved, 'Rationale reviewed and accepted.');

                    continue;
                }

                if ($recipe === 'closed_cannot_reproduce') {
                    $bug = (new RecordNonFixResolution())->handle($admin, $bug, ResolutionOutcome::CannotReproduce, 'Unable to reproduce with the given steps.', [
                        'attempted_steps' => 'Followed the reported steps on the listed environment three times.',
                        'environment' => $this->environmentFor($created),
                    ])->fresh();
                    (new VerifyResolution())->handle($qa, $bug, QAVerificationDecision::Approved, 'Attempts reviewed and accepted.');

                    continue;
                }

                if ($recipe === 'closed_duplicate') {
                    if ($originalForDuplicates === null) {
                        $originalForDuplicates = $bug;

                        continue;
                    }

                    $bug = (new RecordNonFixResolution())->handle($admin, $bug, ResolutionOutcome::Duplicate, 'Already tracked as another report.', [
                        'duplicate_target' => $originalForDuplicates,
                    ])->fresh();
                    (new VerifyResolution())->handle($qa, $bug, QAVerificationDecision::Approved, 'Duplicate confirmed.');
                }
            }
        }

        $markers['PERFMARK-DUP-ORIGINAL-'.$project->key] = $originalForDuplicates?->public_id ?? 'n/a';
    }

    // -- Relationships (related_to / blocks, beyond the duplicate_of pairs above) -------

    /**
     * @param  list<string>  $tierABugIds
     */
    private function createRelationships(Project $project, array $tierABugIds): void
    {
        $admin = Membership::where('project_id', $project->id)
            ->whereHas('roles', fn ($q) => $q->where('role', Role::Admin->value))
            ->with('user')->first()?->user;

        if ($admin === null || count($tierABugIds) < 20) {
            return;
        }

        $action = app(CreateBugRelationship::class);

        for ($i = 0; $i < 5; $i++) {
            $source = Bug::find($tierABugIds[$i * 2]);
            $target = Bug::find($tierABugIds[$i * 2 + 1]);

            if ($source !== null && $target !== null) {
                $action->handle($admin, $source, BugRelationshipType::RelatedTo, $target);
            }
        }

        for ($i = 5; $i < 10; $i++) {
            $source = Bug::find($tierABugIds[$i * 2]);
            $target = Bug::find($tierABugIds[$i * 2 + 1]);

            if ($source !== null && $target !== null) {
                $action->handle($admin, $source, BugRelationshipType::Blocks, $target);
            }
        }
    }
}
