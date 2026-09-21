<script setup lang="ts">
import { ApiError } from '~/plugins/api.client'
import { bugErrorMessage, useBugs, type BugReport } from '~/composables/useBugs'
import { formatDateTime, formatNumber } from '~/utils/presentation'

definePageMeta({ middleware: 'auth' })

type QueueGroup = 'action' | 'waiting' | 'closed'
const bugsApi = useBugs()
const session = useSession()
const assignedBugs = ref<BugReport[]>([])
const loading = ref(true)
const refreshing = ref(false)
const stale = ref(false)
const error = ref('')
const rowError = ref<Record<string, string>>({})
const pendingId = ref<string | null>(null)

const hasDeveloperRole = computed(() => session.user.value?.memberships.some(membership => membership.active && membership.project.active && membership.roles.includes('developer')) || false)
const bugsListTarget = computed(() => session.user.value?.id ? { path: '/bugs', query: { assignee: session.user.value.id } } : '/bugs')
const latestAttempt = (bug: BugReport) => [...bug.resolution_attempts].sort((left, right) => right.attempt_number - left.attempt_number)[0]
const canResume = (bug: BugReport) => {
  const attempt = latestAttempt(bug)
  return bug.status === 'reopened' && bug.allowed_actions.includes('resume_work') && attempt?.outcome === 'fixed' && attempt.qa_result?.decision === 'rejected'
}
const canStart = (bug: BugReport) => bug.status === 'assigned' && bug.allowed_actions.includes('start_work')
const canContinue = (bug: BugReport) => bug.status === 'in_progress' && (bug.allowed_actions.includes('add_progress') || bug.allowed_actions.includes('resolve_fixed'))
function groupFor(bug: BugReport): QueueGroup {
  if (bug.status === 'closed') return 'closed'
  if (canResume(bug) || canContinue(bug) || canStart(bug)) return 'action'
  return 'waiting'
}
const actionBugs = computed(() => ['reopened', 'in_progress', 'assigned'].flatMap(status => assignedBugs.value.filter(bug => groupFor(bug) === 'action' && bug.status === status)))
const waitingBugs = computed(() => assignedBugs.value.filter(bug => groupFor(bug) === 'waiting'))
const closedBugs = computed(() => assignedBugs.value.filter(bug => groupFor(bug) === 'closed'))
const countText = computed(() => `${formatNumber(assignedBugs.value.length)} تخصیص ثبت‌شده`)

function handoff(bug: BugReport): string {
  if (canResume(bug)) return 'ردشده در QA؛ آمادهٔ ادامهٔ کار'
  if (canStart(bug)) return 'آمادهٔ شروع کار'
  if (canContinue(bug)) return 'در حال انجام؛ ثبت پیشرفت یا نتیجهٔ رفع در جزئیات'
  if (bug.status === 'qa_verification') return bug.active_resolution ? `در انتظار تصمیم QA · ${outcomeLabel(bug.active_resolution.outcome)}` : 'در انتظار تصمیم QA'
  if (bug.status === 'reopened') return 'در انتظار بازگشت به بررسی مدیر'
  if (bug.status === 'review') return 'در انتظار اقدام مدیر'
  if (bug.status === 'needs_information') return 'در انتظار پاسخ گزارش‌دهنده'
  if (bug.status === 'submitted') return 'در انتظار آغاز بررسی'
  if (bug.status === 'resolved') return 'اقدام توسعه‌دهنده در این وضعیت در دسترس نیست'
  if (bug.status === 'closed') return bug.active_resolution?.qa_result?.decision === 'approved' ? 'تأییدشده در QA؛ تخصیص تاریخی' : 'تخصیص تاریخی بسته‌شده'
  return 'اقدام توسعه‌دهنده در این وضعیت در دسترس نیست'
}
function outcomeLabel(outcome: string) { return ({ fixed: 'رفع ثبت شده', duplicate: 'تکراری', cannot_reproduce: 'قابل بازتولید نیست', wont_fix: 'رفع نخواهد شد' } as Record<string, string>)[outcome] || outcome }
function detailTarget(bug: BugReport) { return `/bugs/${encodeURIComponent(bug.public_id)}` }

async function load(manual = false): Promise<void> {
  if (refreshing.value || (loading.value && assignedBugs.value.length)) return
  if (manual) refreshing.value = true
  else loading.value = true
  error.value = ''
  try { assignedBugs.value = (await bugsApi.assignedToMe()).data; stale.value = false } catch (caught) {
    error.value = bugErrorMessage(caught)
    if (assignedBugs.value.length) stale.value = true
  } finally { loading.value = false; refreshing.value = false }
}

async function transition(bug: BugReport, action: 'start' | 'resume') {
  if (pendingId.value || stale.value) return
  pendingId.value = bug.id
  rowError.value = { ...rowError.value, [bug.id]: '' }
  try {
    const response = action === 'start' ? await bugsApi.startWork(bug.public_id) : await bugsApi.resumeWork(bug.public_id)
    assignedBugs.value = assignedBugs.value.map(item => item.id === bug.id ? response.data : item)
    await nextTick()
    document.getElementById(`developer-bug-${bug.id}`)?.focus()
    void load(true)
  } catch (caught) {
    const message = caught instanceof ApiError && [403, 404, 409].includes(caught.status || 0)
      ? 'وضعیت یا دسترسی این باگ تغییر کرده است. صف تازه‌سازی می‌شود.'
      : bugErrorMessage(caught)
    rowError.value = { ...rowError.value, [bug.id]: message }
    if (caught instanceof ApiError && [403, 404, 409].includes(caught.status || 0)) void load(true)
  } finally { pendingId.value = null }
}

await load()
</script>

<template>
  <main class="developer-work">
    <section class="developer-toolbar" aria-label="زمینهٔ صف توسعه‌دهنده">
      <div class="developer-toolbar__context"><p>تخصیص‌های شما در همهٔ پروژه‌های دارای نقش فعال توسعه‌دهنده</p><div v-if="!loading && !error" class="developer-toolbar__counts"><span>نیازمند اقدام: <strong>{{ formatNumber(actionBugs.length) }}</strong></span><span>در انتظار دیگران: <strong>{{ formatNumber(waitingBugs.length) }}</strong></span><span v-if="closedBugs.length">بسته‌شده: <strong>{{ formatNumber(closedBugs.length) }}</strong></span><small>{{ countText }}</small></div></div>
      <div class="developer-toolbar__actions"><UButton :to="bugsListTarget" color="neutral" variant="outline" size="sm">نمایش در فهرست باگ‌ها</UButton><UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-refresh-cw" :loading="refreshing" :disabled="loading" @click="load(true)">به‌روزرسانی</UButton></div>
    </section>

    <section v-if="loading && !assignedBugs.length" class="developer-loading" aria-busy="true" aria-live="polite"><span class="sr-only">در حال بارگیری تخصیص‌ها…</span><USkeleton class="h-9 w-full" /><USkeleton v-for="index in 6" :key="index" class="h-16 w-full" /></section>
    <section v-else-if="error && !assignedBugs.length" class="developer-error"><UAlert color="error" variant="subtle" title="بارگیری تخصیص‌ها ناموفق بود" :description="error"><template #actions><UButton size="xs" color="error" variant="outline" @click="load()">تلاش مجدد</UButton><UButton size="xs" color="neutral" variant="ghost" :to="bugsListTarget">فهرست باگ‌ها</UButton></template></UAlert></section>
    <template v-else>
      <UAlert v-if="stale" color="warning" variant="subtle" title="به‌روزرسانی انجام نشد؛ اطلاعات قبلی نمایش داده می‌شود." description="برای جلوگیری از اقدام روی دادهٔ کهنه، شروع و ادامهٔ کار تا تازه‌سازی موفق غیرفعال است." class="developer-stale" />
      <section v-if="!hasDeveloperRole" class="developer-empty"><h2>نقش فعال توسعه‌دهنده ندارید</h2><p>این فضا فقط از تخصیص‌های فعلی نقش توسعه‌دهنده پر می‌شود.</p><UButton :to="bugsListTarget" color="neutral" variant="outline">فهرست باگ‌ها</UButton></section>
      <section v-else-if="!assignedBugs.length" class="developer-empty"><h2>کاری به شما تخصیص داده نشده است</h2><p>در پروژه‌هایی که نقش فعال توسعه‌دهنده دارید، باگی با تخصیص فعلی به شما وجود ندارد.</p><div><UButton color="neutral" variant="outline" @click="load(true)">به‌روزرسانی</UButton><UButton :to="bugsListTarget" color="neutral" variant="ghost">فهرست باگ‌ها</UButton></div></section>
      <template v-else>
        <section class="work-section" :aria-busy="refreshing"><header><h2>اقدام لازم از شما</h2><span>{{ formatNumber(actionBugs.length) }}</span></header><p v-if="!actionBugs.length" class="work-section__empty">در حال حاضر اقدام توسعه‌دهنده‌ای از شما لازم نیست.</p><DeveloperWorkLedger v-else :bugs="actionBugs" group="action" :pending-id="pendingId" :stale="stale" :row-errors="rowError" @start="transition($event, 'start')" @resume="transition($event, 'resume')" /></section>
        <section v-if="waitingBugs.length" class="work-section work-section--quiet" :aria-busy="refreshing"><header><h2>در انتظار تحویل بعدی</h2><span>{{ formatNumber(waitingBugs.length) }}</span></header><DeveloperWorkLedger :bugs="waitingBugs" group="waiting" :pending-id="pendingId" :stale="stale" :row-errors="rowError" /></section>
        <details v-if="closedBugs.length" class="closed-work"><summary>تخصیص‌های بسته‌شده <span>{{ formatNumber(closedBugs.length) }}</span><small>بک‌اند ارجاع مسئول را پس از بسته‌شدن نگه می‌دارد.</small></summary><DeveloperWorkLedger :bugs="closedBugs" group="closed" :pending-id="pendingId" :stale="stale" :row-errors="rowError" /></details>
      </template>
    </template>
  </main>
</template>

<style scoped>
.developer-work{padding:1.5rem}.developer-toolbar{align-items:center;border-bottom:1px solid var(--ui-border);display:flex;gap:1rem;justify-content:space-between;padding-bottom:1rem}.developer-toolbar__context p{font-size:.88rem;margin:0}.developer-toolbar__counts{color:var(--ui-text-muted);display:flex;flex-wrap:wrap;font-size:.76rem;gap:.3rem .8rem;margin-top:.4rem}.developer-toolbar__counts strong{color:var(--ui-text)}.developer-toolbar__counts small{color:var(--ui-text-dimmed)}.developer-toolbar__actions{display:flex;flex:0 0 auto;gap:.35rem}.developer-loading{display:grid;gap:.7rem;margin-top:1.25rem}.developer-error,.developer-stale,.developer-empty{margin-top:1.25rem}.developer-empty{border:1px solid var(--ui-border);max-width:38rem;padding:1rem}.developer-empty h2{font-size:1rem;margin:0 0 .35rem}.developer-empty p{color:var(--ui-text-muted);margin:.35rem 0 1rem}.developer-empty div{display:flex;gap:.5rem}.work-section{margin-top:1.5rem}.work-section--quiet{border-top:1px solid var(--ui-border);padding-top:1.5rem}.work-section header{align-items:center;display:flex;gap:.5rem;margin-bottom:.7rem}.work-section h2{font-size:1rem;margin:0}.work-section header span,.closed-work summary>span{color:var(--ui-text-muted);font-size:.8rem}.work-section__empty{color:var(--ui-text-muted);font-size:.85rem;margin:0}.closed-work{border-top:1px solid var(--ui-border);margin-top:1.5rem;padding-top:1rem}.closed-work summary{cursor:pointer;font-size:1rem;font-weight:650}.closed-work summary small{color:var(--ui-text-muted);font-size:.75rem;font-weight:400;margin-inline-start:.5rem}@media(max-width:767px){.developer-work{padding:1rem}.developer-toolbar{align-items:stretch;flex-direction:column}.developer-toolbar__actions{justify-content:space-between}.developer-toolbar__actions :deep(button),.developer-toolbar__actions :deep(a){min-height:2.75rem}.closed-work summary small{display:block;margin:.35rem 0 0}}
</style>
