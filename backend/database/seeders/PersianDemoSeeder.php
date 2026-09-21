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
 * Deterministic Persian-language demo dataset for manual UI walkthroughs and
 * screenshots — not a SpecKit task, not part of tasks.md. Built entirely
 * through the same domain Actions the real API uses (CreateBug, BeginReview,
 * AssignDeveloper, StartWork, RecordFixedResolution, RecordNonFixResolution,
 * VerifyResolution, RequestInformation, CreateBugRelationship, ...), so
 * every row has a real, policy-checked, activity-logged history — nothing is
 * hand-inserted.
 *
 * Safe to re-run: it rebuilds only the BAZARCHE/POSHTIBAN/KARMANDYAR demo
 * projects and their nested data every time, and never touches the English
 * SHOP/SUPPORT/STAFF demo dataset (DemoSeeder) or any other data.
 *
 * Run with: php artisan db:seed --class=PersianDemoSeeder
 * Never registered in DatabaseSeeder::run(), so it never runs automatically.
 */
class PersianDemoSeeder extends Seeder
{
    private const DEMO_PROJECT_KEYS = ['BAZARCHE', 'POSHTIBAN', 'KARMANDYAR'];

    private const PASSWORD = 'password';

    public function run(): void
    {
        $this->resetDemoData();

        $users = $this->createUsers();

        $bazarche = $this->createProject('BAZARCHE', 'بازارچه', 'پلتفرمی برای فروش آنلاین محصولات، مدیریت سبد خرید و پیگیری سفارش‌های مشتریان.');
        $poshtiban = $this->createProject('POSHTIBAN', 'پشتیبان', 'سامانهٔ تیکتینگ و پشتیبانی مشتریان شامل تیکت‌ها، حساب مشتریان و فضای کاری اپراتورها.');
        $karmandyar = $this->createProject('KARMANDYAR', 'کارمندیار', 'پورتال داخلی منابع انسانی برای مدیریت مرخصی، مدارک و پروفایل کارکنان.');

        $this->addMembers($bazarche, [
            [$users['projectAdmin'], Role::Admin],
            [$users['reporter'], Role::Reporter],
            [$users['developer'], Role::Developer],
            [$users['qa'], Role::QA],
            [$users['maryam'], Role::Reporter],
        ]);
        $this->addMembers($poshtiban, [
            [$users['projectAdmin'], Role::Admin],
            [$users['reporter'], Role::Reporter],
            [$users['developer'], Role::Developer],
            [$users['qa'], Role::QA],
            [$users['maryam'], Role::Developer],
        ]);
        $this->addMembers($karmandyar, [
            [$users['projectAdmin'], Role::Admin],
            [$users['reporter'], Role::Reporter],
            [$users['developer'], Role::Developer],
            [$users['qa'], Role::QA],
            [$users['maryam'], Role::QA],
            [$users['behnam'], Role::Reporter],
            [$users['behnam'], Role::Admin],
        ]);

        $bazarcheTracking = $this->createTrackingValues(
            $bazarche,
            categories: ['پرداخت' => 'payment', 'سبد خرید' => 'cart', 'حساب کاربری' => 'account', 'جست‌وجوی محصول' => 'product-search', 'رابط کاربری' => 'frontend', 'API' => 'api'],
            priorities: ['پایین' => ['low', 1], 'متوسط' => ['medium', 2], 'بالا' => ['high', 3], 'بحرانی' => ['critical', 4]],
            severities: ['جزئی' => ['minor', 1], 'متوسط' => ['moderate', 2], 'عمده' => ['major', 3], 'بحرانی' => ['critical', 4]],
            tags: ['موبایل' => 'mobile', 'بازگشتی' => 'regression', 'درگاه پرداخت' => 'payment-gateway', 'کارایی' => 'performance', 'رابط‌کاربری' => 'ui'],
            resolutionLabels: ['رفع نهایی' => 'final-fix', 'راه‌حل موقت' => 'workaround'],
        );

        $poshtibanTracking = $this->createTrackingValues(
            $poshtiban,
            categories: ['تیکت' => 'ticket', 'احراز هویت' => 'auth', 'فضای کاری اپراتور' => 'agent-workspace', 'اعلان‌ها' => 'notifications', 'پایگاه دانش' => 'knowledge-base', 'API' => 'api'],
            priorities: ['سطح یک' => ['level-1', 1], 'سطح دو' => ['level-2', 2], 'سطح سه' => ['level-3', 3], 'فوری' => ['urgent', 4]],
            severities: ['قابل‌تحمل' => ['tolerable', 1], 'آزاردهنده' => ['annoying', 2], 'مسدودکننده' => ['blocking', 3], 'فاجعه‌بار' => ['catastrophic', 4]],
            tags: ['ایمیل' => 'email', 'بازگشتی' => 'regression', 'دسترسی' => 'permissions', 'کارایی' => 'performance'],
            resolutionLabels: ['پاسخ‌داده‌شد' => 'answered', 'نیازمند بهبود' => 'needs-improvement'],
        );

        $karmandyarTracking = $this->createTrackingValues(
            $karmandyar,
            categories: ['پروفایل کارمند' => 'employee-profile', 'مدیریت مرخصی' => 'leave-management', 'مدارک' => 'documents', 'احراز هویت' => 'auth', 'داشبورد' => 'dashboard', 'API' => 'api'],
            priorities: ['غیرفوری' => ['non-urgent', 1], 'معمولی' => ['normal', 2], 'مهم' => ['important', 3], 'فوری' => ['urgent', 4]],
            severities: ['کم‌اثر' => ['low-impact', 1], 'متوسط' => ['moderate', 2], 'پراثر' => ['high-impact', 3], 'بحرانی' => ['critical', 4]],
            tags: ['دسترسی' => 'permissions', 'رابط‌کاربری' => 'ui', 'بازگشتی' => 'regression', 'مدارک' => 'documents', 'موبایل' => 'mobile'],
            resolutionLabels: ['برطرف‌شد' => 'resolved-label', 'طبق طراحی' => 'by-design'],
        );

        $bazarcheBugs = $this->seedBazarche($bazarche, $users, $bazarcheTracking);
        $poshtibanBugs = $this->seedPoshtiban($poshtiban, $users, $poshtibanTracking);
        $karmandyarBugs = $this->seedKarmandyar($karmandyar, $users, $karmandyarTracking);

        $this->seedRelationships($users, $bazarcheBugs);

        $total = count($bazarcheBugs) + count($poshtibanBugs) + count($karmandyarBugs);
        $this->command?->info("Persian demo dataset ready — 3 projects (BAZARCHE/POSHTIBAN/KARMANDYAR), ".count($users)." users, {$total} bugs.");
    }

    // -- Idempotent reset ----------------------------------------------------------------

    /**
     * Deletes only rows scoped to the BAZARCHE/POSHTIBAN/KARMANDYAR demo
     * projects, in dependency order, then the projects themselves. Demo USER
     * rows are left alone (recreated in place via updateOrCreate) since
     * nothing else ever references them once their memberships are gone.
     * Never touches the English DemoSeeder's SHOP/SUPPORT/STAFF projects,
     * their users, or any other data.
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
            'systemAdmin' => ['email' => 'admin.fa@bugsense.test', 'name' => 'سارا احمدی', 'systemAdmin' => true],
            'projectAdmin' => ['email' => 'parisa@bugsense.test', 'name' => 'پریسا رستمی', 'systemAdmin' => false],
            'reporter' => ['email' => 'reza@bugsense.test', 'name' => 'رضا کریمی', 'systemAdmin' => false],
            'developer' => ['email' => 'negar@bugsense.test', 'name' => 'نگار حسینی', 'systemAdmin' => false],
            'qa' => ['email' => 'arash@bugsense.test', 'name' => 'آرش صادقی', 'systemAdmin' => false],
            'maryam' => ['email' => 'maryam@bugsense.test', 'name' => 'مریم توکلی', 'systemAdmin' => false],
            'behnam' => ['email' => 'behnam@bugsense.test', 'name' => 'بهنام یوسفی', 'systemAdmin' => false],
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
     * @param  list<array{0: User, 1: Role}>  $roleAssignments
     */
    private function addMembers(Project $project, array $roleAssignments): void
    {
        $membershipByUserId = [];

        foreach ($roleAssignments as [$user, $role]) {
            $membership = $membershipByUserId[$user->id] ?? null;

            if ($membership === null) {
                $membership = Membership::create([
                    'project_id' => $project->id,
                    'user_id' => $user->id,
                    'joined_at' => now()->subDays(30),
                ]);
                $membershipByUserId[$user->id] = $membership;
            }

            MembershipRole::create([
                'membership_id' => $membership->id,
                'role' => $role,
                'granted_at' => now()->subDays(30),
                'granted_by_id' => $user->id,
            ]);
        }
    }

    /**
     * @param  array<string, string>  $categories  name => code
     * @param  array<string, array{0: string, 1: int}>  $priorities  name => [code, rank]
     * @param  array<string, array{0: string, 1: int}>  $severities  name => [code, rank]
     * @param  array<string, string>  $tags  name => code
     * @param  array<string, string>  $resolutionLabels  name => code
     * @return array{categories: array<string, string>, priorities: array<string, string>, severities: array<string, string>, tags: array<string, string>, resolutionLabels: array<string, string>}
     */
    private function createTrackingValues(Project $project, array $categories, array $priorities, array $severities, array $tags, array $resolutionLabels): array
    {
        $result = ['categories' => [], 'priorities' => [], 'severities' => [], 'tags' => [], 'resolutionLabels' => []];

        foreach ($categories as $name => $code) {
            $result['categories'][$name] = TrackingValue::create([
                'project_id' => $project->id, 'kind' => 'category', 'code' => $code, 'name' => $name,
            ])->id;
        }

        foreach ($priorities as $name => [$code, $rank]) {
            $result['priorities'][$name] = TrackingValue::create([
                'project_id' => $project->id, 'kind' => 'priority', 'code' => $code, 'name' => $name, 'rank' => $rank,
            ])->id;
        }

        foreach ($severities as $name => [$code, $rank]) {
            $result['severities'][$name] = TrackingValue::create([
                'project_id' => $project->id, 'kind' => 'severity', 'code' => $code, 'name' => $name, 'rank' => $rank,
            ])->id;
        }

        foreach ($tags as $name => $code) {
            $result['tags'][$name] = TrackingValue::create([
                'project_id' => $project->id, 'kind' => 'tag', 'code' => $code, 'name' => $name,
            ])->id;
        }

        foreach ($resolutionLabels as $name => $code) {
            $result['resolutionLabels'][$name] = TrackingValue::create([
                'project_id' => $project->id, 'kind' => 'resolution_label', 'code' => $code, 'name' => $name,
            ])->id;
        }

        return $result;
    }

    // -- Bugs: بازارچه (9) ------------------------------------------------------------------

    /**
     * @return list<Bug>
     */
    private function seedBazarche(Project $project, array $users, array $tracking): array
    {
        $bugs = [];

        // 1. Submitted — waiting for Admin review.
        $bugs['submitted'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'دکمهٔ پرداخت در موبایل واکنش نمی‌دهد',
            'description' => 'در صفحه‌نمایش‌های کوچک، پس از اولین لمس دکمهٔ «ثبت سفارش» هیچ واکنشی نشان نمی‌دهد و گاهی لمس دوباره باعث ثبت دوبارهٔ سفارش می‌شود.',
            'steps_to_reproduce' => "۱. وارد سبد خرید شوید.\n۲. روی دکمهٔ «ثبت سفارش» ضربه بزنید.\n۳. در صورت عدم واکنش، دوباره ضربه بزنید.",
            'expected_result' => 'با یک ضربه، سفارش یک‌بار ثبت شود و به صفحهٔ تأیید برود.',
            'actual_result' => 'دکمه گاهی بی‌پاسخ می‌ماند و لمس دوم باعث ثبت دو سفارش جداگانه می‌شود.',
            'environment' => 'اندروید ۱۴، مرورگر Chrome، اتصال ۴G',
            'platform' => 'موبایل',
            'application_version' => '3.2.1',
            'category_id' => $tracking['categories']['پرداخت'],
            'tag_ids' => [$tracking['tags']['موبایل'], $tracking['tags']['بازگشتی']],
        ]);

        // 2. Review — classified, ready for assignment.
        $bugs['review'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'صفحهٔ تأیید سفارش، اقلام را دوبار نمایش می‌دهد',
            'description' => 'خلاصهٔ سفارش در صفحهٔ تأیید هر قلم را دو بار فهرست می‌کند، در حالی که رسید ایمیلی درست است.',
            'steps_to_reproduce' => "۱. دو محصول متفاوت به سبد خرید اضافه کنید.\n۲. سفارش را نهایی کنید.\n۳. صفحهٔ تأیید سفارش را بررسی کنید.",
            'expected_result' => 'هر قلم دقیقاً یک‌بار در خلاصهٔ سفارش نمایش داده شود.',
            'actual_result' => 'هر قلم دو بار در فهرست تکرار می‌شود.',
            'environment' => 'ویندوز ۱۱، مرورگر Firefox',
            'platform' => 'وب',
            'application_version' => '3.2.1',
            'category_id' => $tracking['categories']['سبد خرید'],
            'tag_ids' => [$tracking['tags']['رابط‌کاربری']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['review']);
        $this->classify($users['projectAdmin'], $bugs['review'], $tracking, 'متوسط', 'متوسط');

        // 3. Needs Information — real Persian open information request.
        $bugs['needsInfo'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'جست‌وجوی محصول، دسته‌بندی انتخاب‌شده را نادیده می‌گیرد',
            'description' => 'با فیلتر کردن کاتالوگ بر اساس دسته‌بندی و سپس جست‌وجو، نتایج از همهٔ دسته‌ها نمایش داده می‌شود، نه فقط دستهٔ انتخاب‌شده.',
            'steps_to_reproduce' => "۱. دستهٔ «لوازم خانگی» را انتخاب کنید.\n۲. عبارتی را جست‌وجو کنید.\n۳. نتایج را بررسی کنید.",
            'expected_result' => 'فقط نتایج مرتبط با دستهٔ انتخاب‌شده نمایش داده شود.',
            'actual_result' => 'نتایج از همهٔ دسته‌بندی‌ها بازگردانده می‌شود.',
            'environment' => 'مک، مرورگر Safari',
            'platform' => 'وب',
            'application_version' => '3.2.0',
            'category_id' => $tracking['categories']['جست‌وجوی محصول'],
            'tag_ids' => [$tracking['tags']['رابط‌کاربری'], $tracking['tags']['بازگشتی']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['needsInfo']);
        $this->classify($users['projectAdmin'], $bugs['needsInfo'], $tracking, 'پایین', 'جزئی');
        app(RequestInformation::class)->handle($users['projectAdmin'], $bugs['needsInfo'], 'لطفاً دقیقاً بفرمایید کدام دسته‌بندی و چه عبارتی را جست‌وجو کردید، و اندازهٔ صفحهٔ نتایج چند بود؟');

        // 4. Assigned to an eligible Developer.
        $bugs['assigned'] = $this->reportBug($users['maryam'], $project, [
            'title' => 'API پرداخت پس از تلاش مجدد خطای ۵۰۰ برمی‌گرداند',
            'description' => 'تلاش مجدد برای پرداخت ناموفق از طریق API، به‌جای دلیل روشن رد شدن، خطای ۵۰۰ برمی‌گرداند و سبد خرید در وضعیت ناسازگار باقی می‌ماند.',
            'steps_to_reproduce' => "۱. با کارت نامعتبر پرداخت را انجام دهید تا رد شود.\n۲. بلافاصله دوباره تلاش کنید.\n۳. پاسخ API را بررسی کنید.",
            'expected_result' => 'خطای ۴۰۰ با پیام روشن دربارهٔ دلیل رد شدن پرداخت بازگردانده شود.',
            'actual_result' => 'خطای ۵۰۰ بدون توضیح بازمی‌گردد و سبد خرید ناسازگار می‌ماند.',
            'environment' => 'محیط Staging، کلاینت curl',
            'platform' => 'API',
            'application_version' => '3.2.1',
            'category_id' => $tracking['categories']['API'],
            'tag_ids' => [$tracking['tags']['درگاه پرداخت']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['assigned']);
        $this->classify($users['projectAdmin'], $bugs['assigned'], $tracking, 'بحرانی', 'عمده');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['assigned']);

        // 5. In Progress.
        $bugs['inProgress'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'مشتری نمی‌تواند آدرس ارسال را ویرایش کند',
            'description' => 'ذخیرهٔ آدرس ارسال جدید روی سفارش موجود بی‌صدا شکست می‌خورد و آدرس قبلی روی سفارش باقی می‌ماند.',
            'steps_to_reproduce' => "۱. وارد حساب کاربری شوید.\n۲. سفارش فعال را باز کنید.\n۳. آدرس ارسال را تغییر داده و ذخیره کنید.",
            'expected_result' => 'آدرس جدید ذخیره و روی سفارش نمایش داده شود.',
            'actual_result' => 'هیچ خطایی نمایش داده نمی‌شود، اما آدرس قبلی بدون تغییر باقی می‌ماند.',
            'environment' => 'اندروید ۱۳، اپلیکیشن موبایل',
            'platform' => 'موبایل',
            'application_version' => '3.2.1',
            'category_id' => $tracking['categories']['حساب کاربری'],
            'tag_ids' => [$tracking['tags']['رابط‌کاربری']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['inProgress']);
        $this->classify($users['projectAdmin'], $bugs['inProgress'], $tracking, 'بالا', 'متوسط');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['inProgress']);
        app(StartWork::class)->handle($users['developer'], $bugs['inProgress']);
        app(AddProgressUpdate::class)->handle($users['developer'], $bugs['inProgress'], 'مشکل به‌صورت محلی بازتولید شد — فرم آدرس معتبر است، اما درخواست به‌روزرسانی پیش از رسیدن به سرویس سفارش حذف می‌شود.');

        // 6. QA Verification — Fixed, waiting for QA (not yet verified).
        $bugs['qaFixed'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'فهرست طولانی محصولات با کندی بارگذاری می‌شود',
            'description' => 'صفحات دسته‌بندی با بیش از چند صد محصول، چند ثانیه طول می‌کشد تا بارگذاری شوند و صفحه لحظاتی قفل می‌کند.',
            'steps_to_reproduce' => "۱. دسته‌بندی با بیش از ۳۰۰ محصول را باز کنید.\n۲. زمان بارگذاری و روانی اسکرول را بررسی کنید.",
            'expected_result' => 'صفحه به‌سرعت بارگذاری شود و اسکرول روان بماند.',
            'actual_result' => 'بارگذاری چند ثانیه طول می‌کشد و صفحه به‌طور کوتاه قفل می‌شود.',
            'environment' => 'ویندوز ۱۱، مرورگر Chrome',
            'platform' => 'وب',
            'application_version' => '3.2.0',
            'category_id' => $tracking['categories']['رابط کاربری'],
            'tag_ids' => [$tracking['tags']['کارایی']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['qaFixed']);
        $this->classify($users['projectAdmin'], $bugs['qaFixed'], $tracking, 'متوسط', 'متوسط');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['qaFixed']);
        app(StartWork::class)->handle($users['developer'], $bugs['qaFixed']);
        app(RecordFixedResolution::class)->handle(
            $users['developer'],
            $bugs['qaFixed'],
            'به‌جای رندر کل فهرست به‌یک‌باره، صفحه‌بندی به جدول محصولات اضافه شد.',
            'دسته‌بندی‌ای با بیش از ۳۰۰ محصول را باز کرده و مطمئن شوید صفحه به‌سرعت بارگذاری و اسکرول روان است.',
        );

        // 7. QA Verification — Cannot Reproduce, waiting for QA (not yet verified).
        $bugs['qaCannotReproduce'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'نشان تعداد سبد خرید پس از حذف کالا به‌روز نمی‌شود',
            'description' => 'شمارندهٔ سبد خرید در سربرگ، چند ثانیه پس از حذف یک کالا همچنان مقدار قبلی را نشان می‌دهد و گاهی هرگز اصلاح نمی‌شود.',
            'steps_to_reproduce' => "۱. دو کالا به سبد خرید اضافه کنید.\n۲. یکی از آن‌ها را حذف کنید.\n۳. نشان سبد خرید در سربرگ را زیر نظر بگیرید.",
            'expected_result' => 'نشان سبد خرید بلافاصله پس از حذف کالا به‌روز شود.',
            'actual_result' => 'نشان چند ثانیه با تأخیر به‌روز می‌شود یا اصلاح نمی‌شود.',
            'environment' => 'لینوکس، مرورگر Chrome',
            'platform' => 'وب',
            'application_version' => '3.2.1',
            'category_id' => $tracking['categories']['رابط کاربری'],
            'tag_ids' => [$tracking['tags']['رابط‌کاربری'], $tracking['tags']['بازگشتی']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['qaCannotReproduce']);
        $this->classify($users['projectAdmin'], $bugs['qaCannotReproduce'], $tracking, 'پایین', 'جزئی');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['qaCannotReproduce']);
        app(StartWork::class)->handle($users['developer'], $bugs['qaCannotReproduce']);
        app(RecordNonFixResolution::class)->handle(
            $users['developer'],
            $bugs['qaCannotReproduce'],
            ResolutionOutcome::CannotReproduce,
            'نشان سبد خرید در تمام تلاش‌ها بلافاصله پس از حذف کالا به‌روزرسانی شد.',
            [
                'attempted_steps' => 'حذف کالا را با یک قلم، چند قلم، و در حالت اتصال کند شبکه ده بار تکرار کردم.',
                'environment' => 'کروم ۱۲۸ روی ویندوز ۱۱ و سافاری ۱۷ روی مک، هر دو با شبکهٔ محلی و شبیه‌ساز اتصال کند.',
            ],
        );

        // 8. Reopened — Fixed attempt rejected by independent QA.
        $bugs['reopened'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'کد تخفیف پس از خروج از حساب همچنان فعال می‌ماند',
            'description' => 'کد تخفیفی که هنگام ورود به حساب وارد شده، حتی پس از خروج نیز روی سبد خرید فعال باقی می‌ماند و امکان استفادهٔ آن در حساب دیگر فراهم می‌شود.',
            'steps_to_reproduce' => "۱. وارد حساب کاربری شوید و یک کد تخفیف اعمال کنید.\n۲. از حساب خارج شوید.\n۳. وضعیت سبد خرید را بررسی کنید.",
            'expected_result' => 'کد تخفیف پس از خروج از حساب، از سبد خرید حذف یا دوباره اعتبارسنجی شود.',
            'actual_result' => 'کد تخفیف فعال باقی می‌ماند و قابل استفاده در حساب دیگر است.',
            'environment' => 'ویندوز ۱۰، مرورگر Edge',
            'platform' => 'وب',
            'application_version' => '3.1.9',
            'category_id' => $tracking['categories']['پرداخت'],
            'tag_ids' => [$tracking['tags']['رابط‌کاربری']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['reopened']);
        $this->classify($users['projectAdmin'], $bugs['reopened'], $tracking, 'متوسط', 'متوسط');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['reopened']);
        app(StartWork::class)->handle($users['developer'], $bugs['reopened']);
        app(RecordFixedResolution::class)->handle(
            $users['developer'],
            $bugs['reopened'],
            'کد تخفیف اکنون هنگام خروج از حساب از سبد خرید پاک می‌شود.',
            'وارد حساب شوید، کد تخفیف را اعمال کنید، خارج شوید و مطمئن شوید سبد خرید دیگر آن کد را نشان نمی‌دهد.',
        );
        app(VerifyResolution::class)->handle($users['qa'], $bugs['reopened'], QAVerificationDecision::Rejected, 'کد تخفیف پس از خروج پاک می‌شود، اما اگر کاربر بدون بستن مرورگر دوباره وارد شود، کد همچنان از حافظهٔ محلی بازیابی و اعمال می‌شود.');

        // 9. Closed — Fixed, independently approved.
        $bugs['closedFixed'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'فیلتر قیمت در صفحهٔ محصولات بازنشانی نمی‌شود',
            'description' => 'پس از اعمال بازهٔ قیمت و سپس پاک کردن فیلترها، بازهٔ قیمت در نوار فیلتر همچنان نمایش داده می‌شود، هرچند نتایج صحیح بازمی‌گردند.',
            'steps_to_reproduce' => "۱. بازهٔ قیمت را تنظیم کنید.\n۲. روی «پاک کردن فیلترها» بزنید.\n۳. نوار فیلتر را بررسی کنید.",
            'expected_result' => 'بازهٔ قیمت در نوار فیلتر نیز پاک شود.',
            'actual_result' => 'مقدار قبلی بازهٔ قیمت در نوار فیلتر باقی می‌ماند.',
            'environment' => 'اندروید ۱۴، مرورگر Chrome',
            'platform' => 'موبایل',
            'application_version' => '3.2.1',
            'category_id' => $tracking['categories']['جست‌وجوی محصول'],
            'tag_ids' => [$tracking['tags']['رابط‌کاربری']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['closedFixed']);
        $this->classify($users['projectAdmin'], $bugs['closedFixed'], $tracking, 'پایین', 'جزئی');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['closedFixed']);
        app(StartWork::class)->handle($users['developer'], $bugs['closedFixed']);
        app(RecordFixedResolution::class)->handle(
            $users['developer'],
            $bugs['closedFixed'],
            'دکمهٔ پاک‌کردن فیلترها اکنون وضعیت بازهٔ قیمت را نیز بازنشانی می‌کند.',
            'بازهٔ قیمت را تنظیم کرده، فیلترها را پاک کنید و مطمئن شوید نوار فیلتر نیز بازنشانی می‌شود.',
        );
        app(VerifyResolution::class)->handle($users['qa'], $bugs['closedFixed'], QAVerificationDecision::Approved, 'در چند بار تکرار، پاک‌کردن فیلترها همیشه بازهٔ قیمت را نیز به‌درستی بازنشانی کرد.');

        return array_values($bugs);
    }

    // -- Bugs: پشتیبان (8) -------------------------------------------------------------------

    /**
     * @return list<Bug>
     */
    private function seedPoshtiban(Project $project, array $users, array $tracking): array
    {
        $bugs = [];

        // 1. Submitted.
        $bugs['submitted'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'ایمیل اطلاع‌رسانی پاسخ تیکت ارسال نمی‌شود',
            'description' => 'مشتریان هنگام پاسخ اپراتور به تیکت، ایمیل اطلاع‌رسانی دریافت نمی‌کنند، هرچند پاسخ به‌درستی در پورتال نمایش داده می‌شود.',
            'steps_to_reproduce' => "۱. تیکتی از طرف مشتری ثبت کنید.\n۲. به‌عنوان اپراتور پاسخ دهید.\n۳. صندوق ایمیل مشتری را بررسی کنید.",
            'expected_result' => 'مشتری بلافاصله ایمیل اطلاع‌رسانی پاسخ را دریافت کند.',
            'actual_result' => 'هیچ ایمیلی ارسال نمی‌شود.',
            'environment' => 'سرور ایمیل تولید، Gmail سمت مشتری',
            'platform' => 'وب',
            'application_version' => '2.5.0',
            'category_id' => $tracking['categories']['اعلان‌ها'],
            'tag_ids' => [$tracking['tags']['ایمیل']],
        ]);

        // 2. Review — classified, ready for assignment.
        $bugs['review'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'اپراتور نمی‌تواند تیکت بسته‌شده را دوباره باز کند',
            'description' => 'کلیک روی «بازگشایی» در تیکت بسته‌شده پیام موفقیت نشان می‌دهد، اما پس از تازه‌سازی صفحه، تیکت همچنان بسته باقی می‌ماند.',
            'steps_to_reproduce' => "۱. تیکت بسته‌شده‌ای را باز کنید.\n۲. روی «بازگشایی» بزنید.\n۳. صفحه را تازه‌سازی کنید.",
            'expected_result' => 'تیکت در وضعیت باز باقی بماند.',
            'actual_result' => 'تیکت پس از تازه‌سازی دوباره بسته نمایش داده می‌شود.',
            'environment' => 'ویندوز ۱۱، مرورگر Chrome',
            'platform' => 'وب',
            'application_version' => '2.5.0',
            'category_id' => $tracking['categories']['تیکت'],
            'tag_ids' => [$tracking['tags']['بازگشتی']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['review']);
        $this->classify($users['projectAdmin'], $bugs['review'], $tracking, 'سطح دو', 'مسدودکننده');

        // 3. Needs Information — real Persian open information request.
        $bugs['needsInfo'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'فهرست تیکت‌ها پس از تازه‌سازی، فیلترها را از دست می‌دهد',
            'description' => 'فیلترهای وضعیت و مسئول اعمال‌شده روی فهرست تیکت‌ها، پس از تازه‌سازی صفحه به حالت پیش‌فرض بازمی‌گردند.',
            'steps_to_reproduce' => "۱. فیلتر وضعیت و مسئول را اعمال کنید.\n۲. صفحه را تازه‌سازی کنید.\n۳. فیلترهای فعال را بررسی کنید.",
            'expected_result' => 'فیلترهای اعمال‌شده پس از تازه‌سازی حفظ شوند.',
            'actual_result' => 'فیلترها به حالت پیش‌فرض بازمی‌گردند.',
            'environment' => 'مک، مرورگر Safari',
            'platform' => 'وب',
            'application_version' => '2.4.8',
            'category_id' => $tracking['categories']['فضای کاری اپراتور'],
            'tag_ids' => [],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['needsInfo']);
        $this->classify($users['projectAdmin'], $bugs['needsInfo'], $tracking, 'سطح یک', 'قابل‌تحمل');
        app(RequestInformation::class)->handle($users['projectAdmin'], $bugs['needsInfo'], 'این مشکل فقط با تازه‌سازی کامل صفحه رخ می‌دهد یا با خروج و بازگشت به فهرست تیکت‌ها هم تکرار می‌شود؟');

        // 4. Assigned.
        $bugs['assigned'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'ورود مشتری دوباره به صفحهٔ ورود هدایت می‌شود',
            'description' => 'پس از وارد کردن اطلاعات صحیح، پورتال مشتری برای لحظه‌ای داشبورد را نشان می‌دهد و سپس دوباره به صفحهٔ ورود هدایت می‌شود.',
            'steps_to_reproduce' => "۱. با اطلاعات معتبر وارد پورتال مشتری شوید.\n۲. چند ثانیه صبر کنید.",
            'expected_result' => 'کاربر در داشبورد باقی بماند.',
            'actual_result' => 'کاربر دوباره به صفحهٔ ورود هدایت می‌شود.',
            'environment' => 'اندروید ۱۳، اپلیکیشن موبایل',
            'platform' => 'موبایل',
            'application_version' => '2.5.0',
            'category_id' => $tracking['categories']['احراز هویت'],
            'tag_ids' => [$tracking['tags']['بازگشتی']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['assigned']);
        $this->classify($users['projectAdmin'], $bugs['assigned'], $tracking, 'فوری', 'فاجعه‌بار');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['assigned']);

        // 5. QA Verification — Won't Fix, waiting for QA (not yet verified).
        $bugs['qaWontFix'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'API پیوست تیکت، فایل‌های حاوی فاصله را رد می‌کند',
            'description' => 'بارگذاری پیوست از طریق API عمومی با نام فایل استاندارد حاوی فاصله، نامعتبر رد می‌شود.',
            'steps_to_reproduce' => "۱. فایلی با نام حاوی فاصله بسازید.\n۲. آن را از طریق API عمومی بارگذاری کنید.\n۳. پاسخ را بررسی کنید.",
            'expected_result' => 'فایل با نام حاوی فاصله بدون خطا پذیرفته شود.',
            'actual_result' => 'درخواست با خطای اعتبارسنجی رد می‌شود.',
            'environment' => 'کلاینت curl ۸.x، محیط Staging',
            'platform' => 'API',
            'application_version' => '2.5.0',
            'category_id' => $tracking['categories']['API'],
            'tag_ids' => [],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['qaWontFix']);
        $this->classify($users['projectAdmin'], $bugs['qaWontFix'], $tracking, 'سطح یک', 'آزاردهنده');
        app(RecordNonFixResolution::class)->handle(
            $users['projectAdmin'],
            $bugs['qaWontFix'],
            ResolutionOutcome::WontFix,
            'این رفتار عمدی است؛ مستندات API صراحتاً نام فایل را بدون فاصله یا کاراکتر خاص توصیه می‌کند.',
            [
                'decision_rationale' => 'تغییر اعتبارسنجی نام فایل ریسک ناسازگاری با سامانه‌های ذخیره‌سازی فایل موجود را دارد؛ راهکار فعلی، رمزگذاری نام فایل پیش از ارسال است که در مستندات ذکر شده.',
            ],
        );

        // 6. In Progress.
        $bugs['inProgress'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'جست‌وجوی پایگاه دانش نتایج نامرتبط برمی‌گرداند',
            'description' => 'جست‌وجوی پیام‌های خطای مشخص در پایگاه دانش، عمدتاً مقالات عمومی نامرتبط را برمی‌گرداند نه صفحهٔ عیب‌یابی مرتبط.',
            'steps_to_reproduce' => "۱. عبارت خطای دقیق را در جست‌وجوی پایگاه دانش وارد کنید.\n۲. نتایج را بررسی کنید.",
            'expected_result' => 'مقالهٔ مرتبط با آن خطا در نتایج بالا قرار گیرد.',
            'actual_result' => 'نتایج عمومی و نامرتبط در بالای فهرست قرار می‌گیرند.',
            'environment' => 'لینوکس، مرورگر Firefox',
            'platform' => 'وب',
            'application_version' => '2.4.9',
            'category_id' => $tracking['categories']['پایگاه دانش'],
            'tag_ids' => [],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['inProgress']);
        $this->classify($users['projectAdmin'], $bugs['inProgress'], $tracking, 'سطح دو', 'آزاردهنده');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['inProgress']);
        app(StartWork::class)->handle($users['developer'], $bugs['inProgress']);
        app(AddProgressUpdate::class)->handle($users['developer'], $bugs['inProgress'], 'در حال حاضر جست‌وجو فقط بر اساس فراوانی کلیدواژه رتبه‌بندی می‌شود؛ افزودن وزن تطابق عنوان در آزمایش محلی به‌وضوح ارتباط نتایج را بهبود می‌دهد.');

        // 7. Closed — non-fix (Won't Fix), independently approved.
        $bugs['closedNonFix'] = $this->reportBug($users['maryam'], $project, [
            'title' => 'اعلان‌های فضای کاری اپراتور تکراری نمایش داده می‌شوند',
            'description' => 'اعلان تیکت جدید گاهی دو بار در منوی زنگولهٔ فضای کاری اپراتور برای همان تیکت ظاهر می‌شود.',
            'steps_to_reproduce' => "۱. فضای کاری را باز نگه دارید تا اتصال قطع و وصل شود.\n۲. تیکت جدیدی ایجاد کنید.\n۳. منوی اعلان‌ها را بررسی کنید.",
            'expected_result' => 'هر تیکت جدید دقیقاً یک اعلان تولید کند.',
            'actual_result' => 'گاهی دو اعلان برای یک تیکت نمایش داده می‌شود.',
            'environment' => 'ویندوز ۱۱، مرورگر Edge',
            'platform' => 'وب',
            'application_version' => '2.4.7',
            'category_id' => $tracking['categories']['فضای کاری اپراتور'],
            'tag_ids' => [$tracking['tags']['بازگشتی']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['closedNonFix']);
        $this->classify($users['projectAdmin'], $bugs['closedNonFix'], $tracking, 'سطح یک', 'آزاردهنده');
        app(RecordNonFixResolution::class)->handle(
            $users['projectAdmin'],
            $bugs['closedNonFix'],
            ResolutionOutcome::WontFix,
            'این رفتار در نسخهٔ فعلی زیرساخت اعلان شناخته‌شده است و بازطراحی آن در نسخهٔ بعدی زیرساخت برنامه‌ریزی شده، نه به‌صورت وصلهٔ فوری.',
            [
                'decision_rationale' => 'رفع فوری نیازمند تغییر در لایهٔ اشتراک اعلان‌ها است که چند بخش دیگر را نیز تحت تأثیر قرار می‌دهد؛ اولویت آن برای بازطراحی نسخهٔ بعدی ثبت شده است.',
            ],
        );
        app(VerifyResolution::class)->handle($users['qa'], $bugs['closedNonFix'], QAVerificationDecision::Approved, 'توضیح منطقی است و ریسک امنیتی یا داده‌ای ندارد؛ ثبت برای بازطراحی آینده تأیید می‌شود.');

        // 8. Closed — Fixed, independently approved.
        $bugs['closedFixed'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'شمارندهٔ تیکت‌های باز در داشبورد اپراتور اشتباه است',
            'description' => 'شمارندهٔ «تیکت‌های باز» در داشبورد اپراتور، تیکت‌های نیازمند اطلاعات بیشتر را نیز به‌اشتباه در شمارش باز لحاظ نمی‌کند.',
            'steps_to_reproduce' => "۱. یک تیکت را در وضعیت «نیازمند اطلاعات بیشتر» قرار دهید.\n۲. شمارندهٔ تیکت‌های باز در داشبورد را بررسی کنید.",
            'expected_result' => 'تیکت‌های نیازمند اطلاعات بیشتر در شمارش تیکت‌های باز لحاظ شوند.',
            'actual_result' => 'این تیکت‌ها از شمارش کسر می‌شوند.',
            'environment' => 'ویندوز ۱۰، مرورگر Chrome',
            'platform' => 'وب',
            'application_version' => '2.4.8',
            'category_id' => $tracking['categories']['تیکت'],
            'tag_ids' => [$tracking['tags']['بازگشتی']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['closedFixed']);
        $this->classify($users['projectAdmin'], $bugs['closedFixed'], $tracking, 'سطح دو', 'آزاردهنده');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['closedFixed']);
        app(StartWork::class)->handle($users['developer'], $bugs['closedFixed']);
        app(RecordFixedResolution::class)->handle(
            $users['developer'],
            $bugs['closedFixed'],
            'محاسبهٔ شمارندهٔ داشبورد اکنون تیکت‌های «نیازمند اطلاعات بیشتر» را نیز جزو باز به‌حساب می‌آورد.',
            'یک تیکت را به وضعیت نیازمند اطلاعات بیشتر ببرید و مطمئن شوید شمارندهٔ داشبورد آن را باز محاسبه می‌کند.',
        );
        app(VerifyResolution::class)->handle($users['qa'], $bugs['closedFixed'], QAVerificationDecision::Approved, 'شمارنده اکنون تیکت‌های نیازمند اطلاعات بیشتر را نیز به‌درستی باز محاسبه می‌کند.');

        return array_values($bugs);
    }

    // -- Bugs: کارمندیار (8) ------------------------------------------------------------------

    /**
     * @return list<Bug>
     */
    private function seedKarmandyar(Project $project, array $users, array $tracking): array
    {
        $bugs = [];

        // 1. Submitted.
        $bugs['submitted'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'مجموع روزهای مرخصی نادرست محاسبه می‌شود',
            'description' => 'ثبت درخواست مرخصی که شامل آخر هفته می‌شود، شنبه و یکشنبه را نیز جزو روزهای مرخصی به حساب می‌آورد و مجموع را بیش از واقع نشان می‌دهد.',
            'steps_to_reproduce' => "۱. درخواست مرخصی از پنج‌شنبه تا دوشنبهٔ هفتهٔ بعد ثبت کنید.\n۲. مجموع روزهای محاسبه‌شده را بررسی کنید.",
            'expected_result' => 'فقط روزهای کاری در مجموع مرخصی محاسبه شود.',
            'actual_result' => 'آخر هفته نیز جزو روزهای مرخصی محاسبه می‌شود.',
            'environment' => 'ویندوز ۱۱، مرورگر Chrome',
            'platform' => 'وب',
            'application_version' => '1.8.0',
            'category_id' => $tracking['categories']['مدیریت مرخصی'],
            'tag_ids' => [$tracking['tags']['بازگشتی']],
        ]);

        // 2. Review — classified.
        $bugs['review'] = $this->reportBug($users['behnam'], $project, [
            'title' => 'عکس پروفایل کارمند پس از تازه‌سازی ناپدید می‌شود',
            'description' => 'بارگذاری عکس پروفایل جدید بلافاصله نمایش داده می‌شود، اما پس از تازه‌سازی صفحه به آواتار پیش‌فرض بازمی‌گردد.',
            'steps_to_reproduce' => "۱. عکس پروفایل جدید بارگذاری کنید.\n۲. صفحه را تازه‌سازی کنید.",
            'expected_result' => 'عکس جدید پس از تازه‌سازی نیز نمایش داده شود.',
            'actual_result' => 'آواتار پیش‌فرض دوباره نمایش داده می‌شود.',
            'environment' => 'مک، مرورگر Safari',
            'platform' => 'وب',
            'application_version' => '1.8.0',
            'category_id' => $tracking['categories']['پروفایل کارمند'],
            'tag_ids' => [$tracking['tags']['رابط‌کاربری']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['review']);
        $this->classify($users['projectAdmin'], $bugs['review'], $tracking, 'غیرفوری', 'کم‌اثر');

        // 3. Assigned.
        $bugs['assigned'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'دانلود مدارک منابع انسانی خطای ۴۰۴ می‌دهد',
            'description' => 'کلیک روی دانلود فیش حقوقی یا نامهٔ منابع انسانی از صفحهٔ مدارک، برای برخی کارمندان خطای «یافت نشد» برمی‌گرداند.',
            'steps_to_reproduce' => "۱. به صفحهٔ مدارک بروید.\n۲. روی دانلود فیش حقوقی کلیک کنید.",
            'expected_result' => 'فایل PDF مربوطه دانلود شود.',
            'actual_result' => 'خطای ۴۰۴ نمایش داده می‌شود.',
            'environment' => 'ویندوز ۱۰، مرورگر Edge',
            'platform' => 'وب',
            'application_version' => '1.7.5',
            'category_id' => $tracking['categories']['مدارک'],
            'tag_ids' => [$tracking['tags']['مدارک']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['assigned']);
        $this->classify($users['projectAdmin'], $bugs['assigned'], $tracking, 'مهم', 'پراثر');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['assigned']);

        // 4. In Progress.
        $bugs['inProgress'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'داشبورد، مانده مرخصی قدیمی را نشان می‌دهد',
            'description' => 'ابزارک مانده مرخصی در داشبورد کارمند، درخواست مرخصی تأییدشدهٔ همان روز را منعکس نمی‌کند.',
            'steps_to_reproduce' => "۱. درخواست مرخصی را تأیید کنید.\n۲. داشبورد کارمند را باز کنید.",
            'expected_result' => 'مانده مرخصی بلافاصله پس از تأیید به‌روزرسانی شود.',
            'actual_result' => 'مانده قدیمی تا تازه‌سازی بعدی کش نمایش داده می‌شود.',
            'environment' => 'اندروید ۱۴، اپلیکیشن موبایل',
            'platform' => 'موبایل',
            'application_version' => '1.8.0',
            'category_id' => $tracking['categories']['داشبورد'],
            'tag_ids' => [$tracking['tags']['رابط‌کاربری'], $tracking['tags']['موبایل']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['inProgress']);
        $this->classify($users['projectAdmin'], $bugs['inProgress'], $tracking, 'معمولی', 'متوسط');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['inProgress']);
        app(StartWork::class)->handle($users['developer'], $bugs['inProgress']);
        app(AddProgressUpdate::class)->handle($users['developer'], $bugs['inProgress'], 'ابزارک داشبورد از یک اسنپ‌شات ذخیره‌شدهٔ مانده می‌خواند؛ در حال اتصال مستقیم آن به سرویس محاسبهٔ زندهٔ مانده هستم.');

        // 5. QA Verification — Fixed, waiting for QA (not yet verified).
        $bugs['qaFixed'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'منوی ناوبری موبایل روی فرم مرخصی قرار می‌گیرد',
            'description' => 'در صفحه‌نمایش‌های باریک، نوار ناوبری پایینی روی دکمهٔ ثبت فرم درخواست مرخصی قرار می‌گیرد و ثبت آن را دشوار می‌کند.',
            'steps_to_reproduce' => "۱. فرم مرخصی را در موبایل باز کنید.\n۲. تا انتهای فرم اسکرول کنید.",
            'expected_result' => 'دکمهٔ ثبت به‌طور کامل قابل مشاهده و لمس باشد.',
            'actual_result' => 'نوار ناوبری دکمه را می‌پوشاند.',
            'environment' => 'iOS ۱۷، Safari موبایل',
            'platform' => 'موبایل',
            'application_version' => '1.8.0',
            'category_id' => $tracking['categories']['مدیریت مرخصی'],
            'tag_ids' => [$tracking['tags']['موبایل'], $tracking['tags']['رابط‌کاربری']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['qaFixed']);
        $this->classify($users['projectAdmin'], $bugs['qaFixed'], $tracking, 'معمولی', 'کم‌اثر');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['qaFixed']);
        app(StartWork::class)->handle($users['developer'], $bugs['qaFixed']);
        app(RecordFixedResolution::class)->handle(
            $users['developer'],
            $bugs['qaFixed'],
            'فاصلهٔ پایینی به فرم اضافه شد تا دکمهٔ ثبت همیشه بالاتر از نوار ناوبری ثابت قرار گیرد.',
            'فرم مرخصی را در صفحه‌نمایش باریک باز کرده و مطمئن شوید دکمهٔ ثبت کاملاً دیده و لمس می‌شود.',
        );

        // 6. Reopened — Fixed attempt rejected by independent QA.
        $bugs['reopened'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'کاربر با دسترسی لغوشده همچنان آیتم منو را می‌بیند',
            'description' => 'پس از لغو دسترسی مدارک یک کارمند، آیتم منوی «مدارک» تا خروج و ورود دوبارهٔ او همچنان قابل مشاهده است.',
            'steps_to_reproduce' => "۱. دسترسی مدارک کاربری واردشده را لغو کنید.\n۲. بدون خروج، منو را بررسی کنید.",
            'expected_result' => 'آیتم منو بلافاصله پس از لغو دسترسی ناپدید شود.',
            'actual_result' => 'آیتم منو تا خروج و ورود دوباره باقی می‌ماند.',
            'environment' => 'ویندوز ۱۱، مرورگر Chrome',
            'platform' => 'وب',
            'application_version' => '1.7.9',
            'category_id' => $tracking['categories']['احراز هویت'],
            'tag_ids' => [$tracking['tags']['دسترسی']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['reopened']);
        $this->classify($users['projectAdmin'], $bugs['reopened'], $tracking, 'مهم', 'متوسط');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['reopened']);
        app(StartWork::class)->handle($users['developer'], $bugs['reopened']);
        app(RecordFixedResolution::class)->handle(
            $users['developer'],
            $bugs['reopened'],
            'نمایش آیتم منو اکنون در هر ناوبری، دسترسی را دوباره بررسی می‌کند نه فقط هنگام ورود.',
            'دسترسی کاربر واردشده را لغو کرده و مطمئن شوید آیتم منو بدون نیاز به خروج ناپدید می‌شود.',
        );
        app(VerifyResolution::class)->handle($users['qa'], $bugs['reopened'], QAVerificationDecision::Rejected, 'آیتم منو ناپدید می‌شود، اما صفحهٔ مدارک همچنان با دسترسی مستقیم از طریق آدرس قابل دسترسی است — دسترسی واقعی هنوز مسدود نشده است.');

        // 7. Closed — Fixed, independently approved.
        $bugs['closedFixed'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'اعلان تأیید مرخصی دو بار ارسال می‌شود',
            'description' => 'پس از تأیید درخواست مرخصی توسط سرپرست، کارمند دو اعلان یکسان تأیید دریافت می‌کند.',
            'steps_to_reproduce' => "۱. درخواست مرخصی را به‌عنوان سرپرست تأیید کنید.\n۲. اعلان‌های کارمند را بررسی کنید.",
            'expected_result' => 'یک اعلان تأیید ارسال شود.',
            'actual_result' => 'دو اعلان یکسان ارسال می‌شود.',
            'environment' => 'ویندوز ۱۱، مرورگر Chrome',
            'platform' => 'وب',
            'application_version' => '1.7.8',
            'category_id' => $tracking['categories']['مدیریت مرخصی'],
            'tag_ids' => [$tracking['tags']['بازگشتی']],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['closedFixed']);
        $this->classify($users['projectAdmin'], $bugs['closedFixed'], $tracking, 'معمولی', 'کم‌اثر');
        $this->assign($users['projectAdmin'], $users['developer'], $bugs['closedFixed']);
        app(StartWork::class)->handle($users['developer'], $bugs['closedFixed']);
        app(RecordFixedResolution::class)->handle(
            $users['developer'],
            $bugs['closedFixed'],
            'رویداد تأیید مرخصی به‌اشتباه دو بار منتشر می‌شد؛ اکنون فقط یک‌بار منتشر می‌شود.',
            'یک درخواست مرخصی را تأیید کرده و مطمئن شوید کارمند فقط یک اعلان دریافت می‌کند.',
        );
        app(VerifyResolution::class)->handle($users['qa'], $bugs['closedFixed'], QAVerificationDecision::Approved, 'در چند بار تکرار تأیید، هر بار دقیقاً یک اعلان دریافت شد.');

        // 8. Closed — non-fix (Cannot Reproduce), independently approved.
        $bugs['closedNonFix'] = $this->reportBug($users['reporter'], $project, [
            'title' => 'فیلد شمارهٔ پرسنلی در فرم پروفایل خالی می‌شود',
            'description' => 'گاهی هنگام باز کردن فرم ویرایش پروفایل، فیلد شمارهٔ پرسنلی به‌جای مقدار واقعی خالی نمایش داده می‌شود.',
            'steps_to_reproduce' => "۱. فرم ویرایش پروفایل را چند بار پیاپی باز کنید.\n۲. فیلد شمارهٔ پرسنلی را بررسی کنید.",
            'expected_result' => 'فیلد همیشه مقدار واقعی شمارهٔ پرسنلی را نشان دهد.',
            'actual_result' => 'گاهی فیلد خالی نمایش داده می‌شود.',
            'environment' => 'ویندوز ۱۰، مرورگر Chrome',
            'platform' => 'وب',
            'application_version' => '1.7.6',
            'category_id' => $tracking['categories']['پروفایل کارمند'],
            'tag_ids' => [],
        ]);
        $this->beginReview($users['projectAdmin'], $bugs['closedNonFix']);
        $this->classify($users['projectAdmin'], $bugs['closedNonFix'], $tracking, 'غیرفوری', 'کم‌اثر');
        app(RecordNonFixResolution::class)->handle(
            $users['projectAdmin'],
            $bugs['closedNonFix'],
            ResolutionOutcome::CannotReproduce,
            'فیلد شمارهٔ پرسنلی در تمام تلاش‌ها همیشه مقدار صحیح را نشان داد.',
            [
                'attempted_steps' => 'فرم را بیست بار پیاپی، با اتصال سریع و کند، و روی سه حساب کارمند متفاوت باز کردم.',
                'environment' => 'کروم ۱۲۸ و اج ۱۲۸ روی ویندوز ۱۱، محیط Staging.',
            ],
        );
        app(VerifyResolution::class)->handle($users['qa'], $bugs['closedNonFix'], QAVerificationDecision::Approved, 'تلاش‌های مستقل نیز مشکل را بازتولید نکرد؛ بستن به‌عنوان غیرقابل‌بازتولید تأیید می‌شود.');

        return array_values($bugs);
    }

    // -- Relationships (same project only) -----------------------------------------------

    /**
     * @param  array<string, User>  $users
     * @param  list<Bug>  $bugs  بازارچه bugs, in creation order (see seedBazarche)
     */
    private function seedRelationships(array $users, array $bugs): void
    {
        $action = app(CreateBugRelationship::class);
        $admin = $users['projectAdmin'];

        // duplicate_of: the freshly Submitted report is treated as a
        // duplicate of the closed price-filter report.
        $action->handle($admin, $bugs[0], BugRelationshipType::DuplicateOf, $bugs[8]);

        // related_to: symmetric, same project.
        $action->handle($admin, $bugs[1], BugRelationshipType::RelatedTo, $bugs[2]);
        $action->handle($admin, $bugs[4], BugRelationshipType::RelatedTo, $bugs[6]);

        // blocks: informational only, never gates the blocked Bug's workflow.
        $action->handle($admin, $bugs[3], BugRelationshipType::Blocks, $bugs[4]);
        $action->handle($admin, $bugs[5], BugRelationshipType::Blocks, $bugs[7]);
    }

    // -- Small per-Bug helpers ------------------------------------------------------------

    private function reportBug(User $reporter, Project $project, array $data): Bug
    {
        return app(CreateBug::class)->handle($reporter, $project, $data);
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
