<?php

namespace Database\Seeders;

use App\Actions\Bugs\AddProgressUpdate;
use App\Actions\Bugs\AssignDeveloper;
use App\Actions\Bugs\BeginReview;
use App\Actions\Bugs\CreateBug;
use App\Actions\Bugs\RecordFixedResolution;
use App\Actions\Bugs\RecordNonFixResolution;
use App\Actions\Bugs\RequestInformation;
use App\Actions\Bugs\SetBugPriority;
use App\Actions\Bugs\SetBugSeverity;
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
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Small, realistic LOCAL demo dataset for manual UI walkthroughs and the
 * university presentation — not a SpecKit task, not part of tasks.md.
 * Safe to re-run: it rebuilds only the SHOP/SUPPORT/STAFF demo projects and
 * their nested data every time, and never touches the `PERF*` performance
 * dataset, any performance user, or unrelated development data.
 *
 * Run with: php artisan db:seed --class=DemoSeeder
 * Never registered in DatabaseSeeder::run(), so it never runs automatically.
 */
class DemoSeeder extends Seeder
{
    private const DEMO_PROJECT_KEYS = ['SHOP', 'SUPPORT', 'STAFF'];

    private const PASSWORD = 'password';

    public function run(): void
    {
        $this->resetDemoData();

        $users = $this->createUsers();

        $shop = $this->createProject('SHOP', 'ShopFlow', 'Customer-facing e-commerce platform with checkout, account, orders, and product browsing.');
        $support = $this->createProject('SUPPORT', 'SupportHub', 'Customer support portal with tickets, customer accounts, agent workflows, and knowledge-base access.');
        $staff = $this->createProject('STAFF', 'StaffDesk', 'Internal employee portal for leave requests, profiles, documents, and HR self-service.');

        $this->addMembers($shop, $users, ['projectAdmin' => Role::Admin, 'reporter' => Role::Reporter, 'developer' => Role::Developer, 'qa' => Role::QA, 'alex' => Role::Reporter]);
        $this->addMembers($support, $users, ['projectAdmin' => Role::Admin, 'reporter' => Role::Reporter, 'developer' => Role::Developer, 'qa' => Role::QA, 'alex' => Role::Developer]);
        $this->addMembers($staff, $users, ['projectAdmin' => Role::Admin, 'reporter' => Role::Reporter, 'developer' => Role::Developer, 'qa' => Role::QA, 'alex' => Role::QA]);

        $shopTracking = $this->createTrackingValues($shop, [
            'Checkout', 'Customer Account', 'Product Catalog', 'Orders', 'Frontend', 'API',
        ], ['mobile', 'regression', 'payment', 'ui', 'api', 'performance']);

        $supportTracking = $this->createTrackingValues($support, [
            'Tickets', 'Authentication', 'Agent Workspace', 'Notifications', 'Knowledge Base', 'API',
        ], ['email', 'ui', 'regression', 'permissions', 'api', 'performance']);

        $staffTracking = $this->createTrackingValues($staff, [
            'Employee Profile', 'Leave Management', 'Documents', 'Authentication', 'Dashboard', 'API',
        ], ['permissions', 'ui', 'regression', 'documents', 'mobile', 'api']);

        $shopBugs = $this->seedShop($shop, $users, $shopTracking);
        $supportBugs = $this->seedSupport($support, $users, $supportTracking);
        $staffBugs = $this->seedStaff($staff, $users, $staffTracking);

        $this->seedRelationships($shop, $users, $shopBugs);

        $this->command?->info('Demo dataset ready — 3 projects (SHOP/SUPPORT/STAFF), 6 users, '
            .(count($shopBugs) + count($supportBugs) + count($staffBugs)).' Bugs.');
    }

    // -- Idempotent reset ----------------------------------------------------------------

    /**
     * Deletes only rows scoped to the SHOP/SUPPORT/STAFF demo projects, in
     * dependency order, then the projects themselves. Demo USER rows are
     * left alone (recreated in place via updateOrCreate) since nothing else
     * ever references them once their memberships are gone. Never touches
     * a `PERF*` project, a `@perf.bugsense.test` user, or anything else.
     */
    private function resetDemoData(): void
    {
        DB::transaction(function () {
            $projectIds = Project::whereIn('key', self::DEMO_PROJECT_KEYS)->pluck('id');

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
            DB::table('memberships')->whereIn('project_id', $projectIds)->delete();
            DB::table('tracking_values')->whereIn('project_id', $projectIds)->delete();
            Project::whereIn('id', $projectIds)->delete();
        });
    }

    // -- Users / projects / memberships / tracking values -------------------------------

    /**
     * @return array<string, User>
     */
    private function createUsers(): array
    {
        $specs = [
            'admin' => ['email' => 'admin@bugsense.test', 'name' => 'Amara Okoye', 'systemAdmin' => true],
            'projectAdmin' => ['email' => 'projectadmin@bugsense.test', 'name' => 'Priya Nandan', 'systemAdmin' => false],
            'reporter' => ['email' => 'reporter@bugsense.test', 'name' => 'Ravi Kumar', 'systemAdmin' => false],
            'developer' => ['email' => 'developer@bugsense.test', 'name' => 'Dana Fischer', 'systemAdmin' => false],
            'qa' => ['email' => 'qa@bugsense.test', 'name' => 'Quinn Alvarez', 'systemAdmin' => false],
            'alex' => ['email' => 'alex@bugsense.test', 'name' => 'Alex Morgan', 'systemAdmin' => false],
        ];

        $users = [];

        foreach ($specs as $key => $spec) {
            $user = User::updateOrCreate(
                ['email' => $spec['email']],
                ['display_name' => $spec['name'], 'password' => self::PASSWORD],
            );
            $user->forceFill(['is_system_admin' => $spec['systemAdmin'], 'is_active' => true, 'deactivated_at' => null, 'deactivated_by_id' => null])->save();
            $users[$key] = $user->fresh();
        }

        return $users;
    }

    private function createProject(string $key, string $name, string $description): Project
    {
        return Project::create(['key' => $key, 'name' => $name, 'description' => $description]);
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, Role>  $roleByUserKey
     */
    private function addMembers(Project $project, array $users, array $roleByUserKey): void
    {
        foreach ($roleByUserKey as $userKey => $role) {
            $user = $users[$userKey];

            $membership = Membership::create([
                'project_id' => $project->id,
                'user_id' => $user->id,
                'joined_at' => now()->subDays(30),
            ]);

            MembershipRole::create([
                'membership_id' => $membership->id,
                'role' => $role,
                'granted_at' => now()->subDays(30),
                'granted_by_id' => $user->id,
            ]);
        }
    }

    /**
     * @param  list<string>  $categoryNames
     * @param  list<string>  $tagNames
     * @return array{categories: array<string, string>, priorities: array<string, string>, severities: array<string, string>, tags: array<string, string>}
     */
    private function createTrackingValues(Project $project, array $categoryNames, array $tagNames): array
    {
        $result = ['categories' => [], 'priorities' => [], 'severities' => [], 'tags' => []];

        foreach ($categoryNames as $name) {
            $result['categories'][$name] = TrackingValue::create([
                'project_id' => $project->id, 'kind' => 'category', 'code' => $this->slug($name), 'name' => $name,
            ])->id;
        }

        foreach (['Low' => 1, 'Medium' => 2, 'High' => 3, 'Critical' => 4] as $name => $rank) {
            $result['priorities'][$name] = TrackingValue::create([
                'project_id' => $project->id, 'kind' => 'priority', 'code' => $this->slug($name), 'name' => $name, 'rank' => $rank,
            ])->id;
        }

        foreach (['Minor' => 1, 'Moderate' => 2, 'Major' => 3, 'Critical' => 4] as $name => $rank) {
            $result['severities'][$name] = TrackingValue::create([
                'project_id' => $project->id, 'kind' => 'severity', 'code' => $this->slug($name), 'name' => $name, 'rank' => $rank,
            ])->id;
        }

        foreach ($tagNames as $name) {
            $result['tags'][$name] = TrackingValue::create([
                'project_id' => $project->id, 'kind' => 'tag', 'code' => $this->slug($name), 'name' => $name,
            ])->id;
        }

        return $result;
    }

    private function slug(string $name): string
    {
        return strtolower(str_replace(' ', '-', $name));
    }

    // -- Bugs -----------------------------------------------------------------------------

    /**
     * @return list<Bug>
     */
    private function seedShop(Project $project, array $users, array $tracking): array
    {
        $bugs = [];

        // Submitted: freshly reported, unclassified — realistic (only an
        // Admin sets priority/severity, and that hasn't happened yet).
        $bugs['submitted'] = $this->reportBug($users['reporter'], $project,
            'Checkout button becomes unresponsive on mobile',
            'On small screens the "Place order" button stops responding after the first tap; a second tap sometimes double-submits the order.',
            $tracking['categories']['Checkout'], [$tracking['tags']['mobile'], $tracking['tags']['regression']],
        );

        // Review: triaged, classified, awaiting a decision.
        $bugs['review'] = $this->reportBug($users['reporter'], $project,
            'Order confirmation page shows duplicate items',
            'The order summary on the confirmation page lists each line item twice, though the emailed receipt is correct.',
            $tracking['categories']['Orders'], [$tracking['tags']['ui']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['review']);
        $this->classify($users['projectAdmin'], $bugs['review'], $tracking, 'Medium', 'Moderate');

        // Needs Information.
        $bugs['needsInfo'] = $this->reportBug($users['reporter'], $project,
            'Product search ignores selected category',
            'Filtering the catalog by category and then searching returns results from every category, not just the selected one.',
            $tracking['categories']['Product Catalog'], [$tracking['tags']['ui'], $tracking['tags']['regression']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['needsInfo']);
        $this->classify($users['projectAdmin'], $bugs['needsInfo'], $tracking, 'Low', 'Minor');
        app(RequestInformation::class)->handle($users['projectAdmin'], $bugs['needsInfo'], 'Which category and search term did you use, and on which page size setting?');

        // Assigned.
        $bugs['assigned'] = $this->reportBug($users['alex'], $project,
            'Payment API returns 500 after retry',
            'Retrying a failed payment through the checkout API returns a 500 instead of a clear decline reason, and the cart is left in an inconsistent state.',
            $tracking['categories']['API'], [$tracking['tags']['payment'], $tracking['tags']['api']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['assigned']);
        $this->classify($users['projectAdmin'], $bugs['assigned'], $tracking, 'Critical', 'Major');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['assigned']);

        // In Progress.
        $bugs['inProgress'] = $this->reportBug($users['reporter'], $project,
            'Customer cannot update shipping address',
            'Saving a new shipping address on an existing order silently fails; the old address remains on the order.',
            $tracking['categories']['Customer Account'], [$tracking['tags']['ui']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['inProgress']);
        $this->classify($users['projectAdmin'], $bugs['inProgress'], $tracking, 'High', 'Moderate');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['inProgress']);
        app(StartWork::class)->handle($users['developer'], $bugs['inProgress']);
        app(AddProgressUpdate::class)->handle($users['developer'], $bugs['inProgress'], 'Reproduced locally — the address form validates but the update request is dropped before it reaches the order service.');

        // QA Verification.
        $bugs['qaVerification'] = $this->reportBug($users['reporter'], $project,
            'Large product list loads slowly',
            'Category pages with more than a few hundred products take several seconds to render and briefly freeze the page.',
            $tracking['categories']['Frontend'], [$tracking['tags']['performance']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['qaVerification']);
        $this->classify($users['projectAdmin'], $bugs['qaVerification'], $tracking, 'Medium', 'Moderate');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['qaVerification']);
        app(StartWork::class)->handle($users['developer'], $bugs['qaVerification']);
        app(RecordFixedResolution::class)->handle($users['developer'], $bugs['qaVerification'], 'Added pagination to the product grid instead of rendering the full list at once.', 'Open a category with 300+ products and confirm the page loads promptly and scrolling stays smooth.');

        // Reopened: Fixed attempt rejected by independent QA.
        $bugs['reopened'] = $this->reportBug($users['reporter'], $project,
            'Cart badge does not update after removing item',
            'The header cart count keeps the old total for a few seconds after removing an item, and sometimes never corrects itself.',
            $tracking['categories']['Frontend'], [$tracking['tags']['ui'], $tracking['tags']['regression']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['reopened']);
        $this->classify($users['projectAdmin'], $bugs['reopened'], $tracking, 'Low', 'Minor');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['reopened']);
        app(StartWork::class)->handle($users['developer'], $bugs['reopened']);
        app(RecordFixedResolution::class)->handle($users['developer'], $bugs['reopened'], 'Refreshed the cart badge from the removal response instead of a separate polling call.', 'Remove an item from the cart and confirm the header count updates immediately.');
        app(VerifyResolution::class)->handle($users['qa'], $bugs['reopened'], QAVerificationDecision::Rejected, 'The badge still lags by a couple of seconds on the orders page specifically — not fully fixed yet.');

        // Closed: non-fix outcome (Won't Fix), independently approved.
        $bugs['closed'] = $this->reportBug($users['reporter'], $project,
            'Discount code remains applied after logout',
            'A discount code entered while signed in stays applied to the cart even after signing out, letting it be reused on a different account.',
            $tracking['categories']['Checkout'], [$tracking['tags']['ui']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['closed']);
        $this->classify($users['projectAdmin'], $bugs['closed'], $tracking, 'Medium', 'Minor');
        app(RecordNonFixResolution::class)->handle($users['projectAdmin'], $bugs['closed'], ResolutionOutcome::WontFix, 'Cart contents intentionally persist across sign-out by design; the discount is re-validated at checkout.', [
            'decision_rationale' => 'Re-validating the code server-side at checkout already prevents any actual misuse; changing cart-persistence behavior would affect unrelated features.',
        ]);
        app(VerifyResolution::class)->handle($users['qa'], $bugs['closed'], QAVerificationDecision::Approved, 'Confirmed the checkout re-validation rejects the code once it no longer applies to the signed-in account.');

        return array_values($bugs);
    }

    /**
     * @return list<Bug>
     */
    private function seedSupport(Project $project, array $users, array $tracking): array
    {
        $bugs = [];

        $bugs['submitted'] = $this->reportBug($users['reporter'], $project,
            'Ticket notification email is not sent',
            'Customers do not receive an email when an agent replies to their ticket, though the reply appears correctly in the portal.',
            $tracking['categories']['Notifications'], [$tracking['tags']['email']],
        );

        $bugs['review'] = $this->reportBug($users['reporter'], $project,
            'Agent cannot reopen a closed customer ticket',
            'Clicking "Reopen" on a closed ticket shows a success message but the ticket stays closed after refreshing.',
            $tracking['categories']['Tickets'], [$tracking['tags']['regression']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['review']);
        $this->classify($users['projectAdmin'], $bugs['review'], $tracking, 'High', 'Major');

        $bugs['needsInfo'] = $this->reportBug($users['reporter'], $project,
            'Ticket list loses filters after refresh',
            'Applied status and assignee filters on the ticket list reset to defaults after refreshing the page.',
            $tracking['categories']['Agent Workspace'], [$tracking['tags']['ui']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['needsInfo']);
        $this->classify($users['projectAdmin'], $bugs['needsInfo'], $tracking, 'Low', 'Minor');
        app(RequestInformation::class)->handle($users['projectAdmin'], $bugs['needsInfo'], 'Does this happen on a hard refresh only, or also when navigating away and back?');

        $bugs['assigned'] = $this->reportBug($users['reporter'], $project,
            'Customer login redirects back to sign-in',
            'After entering correct credentials, the customer portal briefly shows the dashboard then redirects back to the sign-in page.',
            $tracking['categories']['Authentication'], [$tracking['tags']['regression'], $tracking['tags']['ui']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['assigned']);
        $this->classify($users['projectAdmin'], $bugs['assigned'], $tracking, 'Critical', 'Critical');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['assigned']);

        $bugs['inProgress'] = $this->reportBug($users['reporter'], $project,
            'Knowledge base search returns unrelated articles',
            'Searching the knowledge base for specific error messages mostly returns unrelated general articles instead of the matching troubleshooting page.',
            $tracking['categories']['Knowledge Base'], [$tracking['tags']['ui']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['inProgress']);
        $this->classify($users['projectAdmin'], $bugs['inProgress'], $tracking, 'Medium', 'Moderate');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['inProgress']);
        app(StartWork::class)->handle($users['developer'], $bugs['inProgress']);
        app(AddProgressUpdate::class)->handle($users['developer'], $bugs['inProgress'], 'Search is currently ranking by keyword frequency only; adding a title-match weight noticeably improves relevance in local testing.');

        $bugs['closedFixed'] = $this->reportBug($users['reporter'], $project,
            'Agent workspace shows duplicate notifications',
            'New-ticket notifications occasionally appear twice in the agent workspace bell menu for the same ticket.',
            $tracking['categories']['Agent Workspace'], [$tracking['tags']['regression']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['closedFixed']);
        $this->classify($users['projectAdmin'], $bugs['closedFixed'], $tracking, 'Medium', 'Minor');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['closedFixed']);
        app(StartWork::class)->handle($users['developer'], $bugs['closedFixed']);
        app(RecordFixedResolution::class)->handle($users['developer'], $bugs['closedFixed'], 'The notification listener was subscribed twice on reconnect; now guarded so it only subscribes once.', 'Leave the workspace open across a reconnect and confirm each new ticket produces exactly one notification.');
        app(VerifyResolution::class)->handle($users['qa'], $bugs['closedFixed'], QAVerificationDecision::Approved, 'Reconnected several times and only saw single notifications throughout.');

        $bugs['closedNonFix'] = $this->reportBug($users['reporter'], $project,
            'API rejects valid ticket attachment metadata',
            'Uploading an attachment through the public API with a standard filename containing a space is rejected as invalid.',
            $tracking['categories']['API'], [$tracking['tags']['api']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['closedNonFix']);
        $this->classify($users['projectAdmin'], $bugs['closedNonFix'], $tracking, 'Low', 'Minor');
        app(RecordNonFixResolution::class)->handle($users['projectAdmin'], $bugs['closedNonFix'], ResolutionOutcome::CannotReproduce, 'Standard filenames with spaces were accepted in every attempt.', [
            'attempted_steps' => 'Uploaded attachments with spaces, punctuation, and mixed-case extensions via the documented API example three times.',
            'environment' => 'API client: curl 8.x; staging ticket API.',
        ]);
        app(VerifyResolution::class)->handle($users['qa'], $bugs['closedNonFix'], QAVerificationDecision::Approved, 'Repeated the same attempts and could not reproduce a rejection either.');

        return array_values($bugs);
    }

    /**
     * @return list<Bug>
     */
    private function seedStaff(Project $project, array $users, array $tracking): array
    {
        $bugs = [];

        $bugs['submitted'] = $this->reportBug($users['reporter'], $project,
            'Leave request total is calculated incorrectly',
            'Submitting a leave request spanning a weekend counts the Saturday and Sunday as leave days, inflating the requested total.',
            $tracking['categories']['Leave Management'], [$tracking['tags']['regression']],
        );

        $bugs['review'] = $this->reportBug($users['reporter'], $project,
            'Employee profile photo disappears after refresh',
            'Uploading a new profile photo shows it immediately, but refreshing the page reverts to the default avatar.',
            $tracking['categories']['Employee Profile'], [$tracking['tags']['ui']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['review']);
        $this->classify($users['projectAdmin'], $bugs['review'], $tracking, 'Low', 'Minor');

        $bugs['assigned'] = $this->reportBug($users['reporter'], $project,
            'HR document download returns 404',
            'Clicking to download a payslip or HR letter from the Documents page returns a "not found" error for some employees.',
            $tracking['categories']['Documents'], [$tracking['tags']['documents']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['assigned']);
        $this->classify($users['projectAdmin'], $bugs['assigned'], $tracking, 'High', 'Major');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['assigned']);

        $bugs['inProgress'] = $this->reportBug($users['reporter'], $project,
            'Dashboard shows outdated leave balance',
            'The employee dashboard leave-balance widget does not reflect a leave request approved earlier the same day.',
            $tracking['categories']['Dashboard'], [$tracking['tags']['ui']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['inProgress']);
        $this->classify($users['projectAdmin'], $bugs['inProgress'], $tracking, 'Medium', 'Moderate');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['inProgress']);
        app(StartWork::class)->handle($users['developer'], $bugs['inProgress']);
        app(AddProgressUpdate::class)->handle($users['developer'], $bugs['inProgress'], 'The dashboard widget reads from a cached balance snapshot; wiring it to the live balance endpoint instead.');

        $bugs['qaVerification'] = $this->reportBug($users['reporter'], $project,
            'Mobile navigation covers the leave form',
            'On narrow screens the bottom navigation bar overlaps the submit button on the leave-request form, making it hard to submit.',
            $tracking['categories']['Leave Management'], [$tracking['tags']['mobile'], $tracking['tags']['ui']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['qaVerification']);
        $this->classify($users['projectAdmin'], $bugs['qaVerification'], $tracking, 'Medium', 'Minor');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['qaVerification']);
        app(StartWork::class)->handle($users['developer'], $bugs['qaVerification']);
        app(RecordFixedResolution::class)->handle($users['developer'], $bugs['qaVerification'], 'Added bottom padding to the form so the submit button always clears the fixed navigation bar.', 'Open the leave form on a narrow screen and confirm the submit button is fully visible and tappable.');

        $bugs['reopened'] = $this->reportBug($users['reporter'], $project,
            'User with revoked permission still sees menu item',
            'After an employee\'s document-access permission is revoked, the Documents menu item remains visible until they sign out and back in.',
            $tracking['categories']['Authentication'], [$tracking['tags']['permissions']],
        );
        $this->beginReview($users['projectAdmin'], $bugs['reopened']);
        $this->classify($users['projectAdmin'], $bugs['reopened'], $tracking, 'High', 'Moderate');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['reopened']);
        app(StartWork::class)->handle($users['developer'], $bugs['reopened']);
        app(RecordFixedResolution::class)->handle($users['developer'], $bugs['reopened'], 'Menu visibility now re-checks the permission on every navigation instead of only at login.', 'Revoke the permission for a signed-in user and confirm the menu item disappears without needing to sign out.');
        app(VerifyResolution::class)->handle($users['qa'], $bugs['reopened'], QAVerificationDecision::Rejected, 'The menu item disappears, but the underlying Documents page is still directly reachable by URL — access itself is not yet blocked.');

        return array_values($bugs);
    }

    // -- Relationships (kept small, same project only) ----------------------------------

    /**
     * @param  array<string, User>  $users
     * @param  list<Bug>  $bugs
     */
    private function seedRelationships(Project $project, array $users, array $bugs): void
    {
        $action = app(CreateBugRelationship::class);
        $admin = $users['projectAdmin'];

        // duplicate_of: the freshly Submitted report is treated as a
        // duplicate of the closed discount-code report.
        $action->handle($admin, $bugs[0], BugRelationshipType::DuplicateOf, $bugs[7]);

        // related_to: symmetric, same project.
        $action->handle($admin, $bugs[1], BugRelationshipType::RelatedTo, $bugs[2]);
        $action->handle($admin, $bugs[3], BugRelationshipType::RelatedTo, $bugs[5]);

        // blocks: informational only, never gates the blocked Bug's workflow.
        $action->handle($admin, $bugs[3], BugRelationshipType::Blocks, $bugs[4]);
        $action->handle($admin, $bugs[4], BugRelationshipType::Blocks, $bugs[6]);
    }

    // -- Small per-Bug helpers ------------------------------------------------------------

    private function reportBug(User $reporter, Project $project, string $title, string $description, string $categoryId, array $tagIds): Bug
    {
        return app(CreateBug::class)->handle($reporter, $project, [
            'title' => $title,
            'description' => $description,
            'category_id' => $categoryId,
            'tag_ids' => $tagIds,
        ]);
    }

    private function beginReview(User $admin, Bug $bug): void
    {
        app(BeginReview::class)->handle($admin, $bug);
    }

    /**
     * @param  array{priorities: array<string, string>, severities: array<string, string>}  $tracking
     */
    private function classify(User $admin, Bug $bug, array $tracking, string $priorityName, string $severityName): void
    {
        app(SetBugPriority::class)->handle($admin, $bug, $tracking['priorities'][$priorityName]);
        app(SetBugSeverity::class)->handle($admin, $bug, $tracking['severities'][$severityName]);
    }

    private function assign(User $admin, User $developer, Bug $bug): void
    {
        app(AssignDeveloper::class)->handle($admin, $bug, $developer->id);
    }
}
