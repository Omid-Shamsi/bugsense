<script setup lang="ts">
import { bugErrorMessage, useBugs, type BugReport } from '~/composables/useBugs'
import { formatNumber } from '~/utils/presentation'

definePageMeta({ middleware: 'auth' })

const bugsApi = useBugs()
const session = useSession()
const queuedBugs = ref<BugReport[]>([])
const loading = ref(true)
const refreshing = ref(false)
const stale = ref(false)
const error = ref('')

const hasQaRole = computed(() => session.user.value?.memberships.some(membership => membership.active && membership.project.active && membership.roles.includes('qa')) || false)
const knownOutcomes = new Set(['fixed', 'duplicate', 'cannot_reproduce', 'wont_fix'])
const normalRow = (bug: BugReport) => {
  const attempt = bug.active_resolution
  return Boolean(session.user.value && bug.status === 'qa_verification' && bug.project.active && attempt && !attempt.qa_result && attempt.recorded_by.id !== session.user.value.id && knownOutcomes.has(attempt.outcome) && bug.allowed_actions.includes('verify'))
}
const fixedBugs = computed(() => queuedBugs.value.filter(bug => normalRow(bug) && bug.active_resolution?.outcome === 'fixed'))
const nonFixBugs = computed(() => queuedBugs.value.filter(bug => normalRow(bug) && bug.active_resolution && bug.active_resolution.outcome !== 'fixed'))
const inconsistentBugs = computed(() => queuedBugs.value.filter(bug => !normalRow(bug)))
const actionableCount = computed(() => fixedBugs.value.length + nonFixBugs.value.length)

async function load(manual = false): Promise<void> {
  if (refreshing.value || (loading.value && queuedBugs.value.length)) return
  if (manual) refreshing.value = true
  else loading.value = true
  error.value = ''
  try {
    queuedBugs.value = (await bugsApi.qaQueue()).data
    stale.value = false
  } catch (caught) {
    error.value = bugErrorMessage(caught)
    if (queuedBugs.value.length) stale.value = true
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

onActivated(() => { void load(true) })
await load()
</script>

<template>
  <main class="qa-work">
    <section class="qa-toolbar" aria-label="زمینهٔ صف بررسی QA">
      <div class="qa-toolbar__context">
        <p>مواردی که در پروژه‌های دارای نقش فعال <bdi dir="ltr">QA</bdi> می‌توانید مستقل بررسی کنید</p>
        <div v-if="!loading && !error" class="qa-toolbar__counts"><span>آماده بررسی: <strong>{{ formatNumber(actionableCount) }}</strong></span><span>رفع: <strong>{{ formatNumber(fixedBugs.length) }}</strong></span><span>نتیجهٔ غیررفع: <strong>{{ formatNumber(nonFixBugs.length) }}</strong></span></div>
        <div v-else-if="loading && !queuedBugs.length" class="qa-toolbar__skeleton" aria-hidden="true"><USkeleton v-for="index in 3" :key="index" class="h-3 w-20" /></div>
        <small>همهٔ موارد این صف در وضعیت بررسی <bdi dir="ltr">QA</bdi> هستند؛ حضور در صف به‌معنای تأیید نتیجه نیست.</small>
      </div>
      <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-refresh-cw" :loading="refreshing" :disabled="loading" @click="load(true)">به‌روزرسانی</UButton>
    </section>

    <section v-if="loading && !queuedBugs.length" class="qa-loading" aria-busy="true" aria-live="polite"><span class="sr-only">در حال بارگیری صف بررسی QA…</span><USkeleton class="h-9 w-full" /><USkeleton v-for="index in 6" :key="index" class="h-16 w-full" /></section>
    <section v-else-if="error && !queuedBugs.length" class="qa-error"><UAlert color="error" variant="subtle" title="بارگیری صف QA ناموفق بود" :description="error"><template #actions><UButton size="xs" color="error" variant="outline" @click="load()">تلاش مجدد</UButton><UButton size="xs" color="neutral" variant="ghost" to="/bugs">فهرست باگ‌ها</UButton></template></UAlert></section>
    <template v-else>
      <UAlert v-if="stale" color="warning" variant="subtle" title="به‌روزرسانی صف انجام نشد؛ اطلاعات قبلی نمایش داده می‌شود." description="برای جلوگیری از تصمیم‌گیری روی دادهٔ کهنه، جزئیات باگ را باز کنید تا وضعیت و اختیار فعلی دوباره بررسی شود." class="qa-stale" />
      <section v-if="session.status.value === 'authenticated' && !hasQaRole" class="qa-empty"><h2>نقش فعال QA ندارید</h2><p>این صف به نقش فعال QA در یک پروژهٔ فعال نیاز دارد؛ مدیر بودن به‌تنهایی اختیار بررسی QA نمی‌دهد.</p><UButton to="/bugs" color="neutral" variant="outline">فهرست باگ‌ها</UButton></section>
      <section v-else-if="!queuedBugs.length" class="qa-empty"><h2>موردی برای بررسی مستقل وجود ندارد</h2><p>در حال حاضر هیچ نتیجهٔ فعالی در پروژه‌های QA شما وجود ندارد که توسط شخص دیگری ثبت شده و آمادهٔ تصمیم شما باشد.</p><UButton color="neutral" variant="outline" @click="load(true)">به‌روزرسانی</UButton></section>
      <template v-else>
        <section v-if="fixedBugs.length" class="qa-section" :aria-busy="refreshing"><header><div><h2>بررسی رفع</h2><p>نتیجه‌های Fixed که باید بر اساس گزارش، شواهد و دستورهای QA دوباره آزموده شوند.</p></div><span>{{ formatNumber(fixedBugs.length) }}</span></header><QAWorkLedger :bugs="fixedBugs" :actionable="!stale" /></section>
        <section v-if="nonFixBugs.length" class="qa-section qa-section--quiet" :aria-busy="refreshing"><header><div><h2>بازبینی نتیجهٔ غیررفع</h2><p>تصمیم‌های Duplicate، Cannot Reproduce و Won’t Fix که پیش از بسته‌شدن به بررسی مستقل QA نیاز دارند.</p></div><span>{{ formatNumber(nonFixBugs.length) }}</span></header><QAWorkLedger :bugs="nonFixBugs" :actionable="!stale" /></section>
        <section v-if="inconsistentBugs.length" class="qa-section qa-section--quiet" :aria-busy="refreshing"><header><div><h2>نیازمند بررسی وضعیت</h2><p>این ردیف با وضعیت یا اختیار فعلی صف هم‌خوان نیست. برای دریافت وضعیت جدید، صف را به‌روزرسانی کنید.</p></div><span>{{ formatNumber(inconsistentBugs.length) }}</span></header><QAWorkLedger :bugs="inconsistentBugs" inconsistent /></section>
      </template>
    </template>
  </main>
</template>

<style scoped>
.qa-work{padding:1.5rem}.qa-toolbar{align-items:center;border-bottom:1px solid var(--ui-border);display:flex;gap:1rem;justify-content:space-between;padding-bottom:1rem}.qa-toolbar__context p{font-size:.88rem;margin:0}.qa-toolbar__context small{color:var(--ui-text-muted);display:block;font-size:.76rem;margin-top:.45rem}.qa-toolbar__counts,.qa-toolbar__skeleton{color:var(--ui-text-muted);display:flex;flex-wrap:wrap;font-size:.76rem;gap:.3rem .8rem;margin-top:.4rem}.qa-toolbar__counts strong{color:var(--ui-text)}.qa-loading{display:grid;gap:.7rem;margin-top:1.25rem}.qa-error,.qa-stale,.qa-empty{margin-top:1.25rem}.qa-empty{border:1px solid var(--ui-border);max-width:38rem;padding:1rem}.qa-empty h2{font-size:1rem;margin:0 0 .35rem}.qa-empty p{color:var(--ui-text-muted);margin:.35rem 0 1rem}.qa-section{margin-top:1.5rem}.qa-section--quiet{border-top:1px solid var(--ui-border);padding-top:1.5rem}.qa-section header{align-items:flex-start;display:flex;gap:.75rem;justify-content:space-between;margin-bottom:.7rem}.qa-section h2{font-size:1rem;margin:0}.qa-section header p{color:var(--ui-text-muted);font-size:.82rem;margin:.3rem 0 0}.qa-section header>span{color:var(--ui-text-muted);font-size:.8rem}@media(max-width:767px){.qa-work{padding:1rem}.qa-toolbar{align-items:stretch;flex-direction:column}.qa-toolbar :deep(button),.qa-empty :deep(a){min-height:2.75rem}.qa-section header{gap:.5rem}}
</style>
