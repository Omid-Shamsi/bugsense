<script setup lang="ts">
import BugFilters from '~/components/bugs/BugFilters.vue'
import { bugErrorMessage, useBugs, type BugReport, type BugTrackingValue } from '~/composables/useBugs'
import {
  cloneBugFilterState,
  discoveryErrorMessage,
  filtersFromQuery,
  filtersToRouteQuery,
  getBugListState,
  useBugDiscovery,
  type BugFilterState,
  type PaginationMeta,
} from '~/composables/useBugFilters'
import { formatDateTime, formatNumber, statusLabel } from '~/utils/presentation'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const router = useRouter()
const session = useSession()
const workspace = useWorkspace()
const bugsApi = useBugs()
const discovery = useBugDiscovery()
const filters = ref<BugFilterState>(filtersFromQuery(route.query as Record<string, unknown>))
const bugs = ref<BugReport[]>([])
const meta = ref<PaginationMeta | null>(null)
const trackingValues = ref<BugTrackingValue[]>([])
const loading = ref(true)
const optionsLoading = ref(false)
const error = ref('')
const optionsError = ref('')
let requestVersion = 0
let optionsVersion = 0

const state = computed(() => getBugListState(loading.value, error.value, bugs.value.length))

async function load() {
  const version = ++requestVersion
  loading.value = true
  error.value = ''
  try {
    const response = await discovery.list(filters.value)
    if (version !== requestVersion) return
    bugs.value = response.data
    meta.value = response.meta
  } catch (caught) {
    if (version === requestVersion) {
      bugs.value = []
      meta.value = null
      error.value = discoveryErrorMessage(caught)
    }
  } finally {
    if (version === requestVersion) loading.value = false
  }
}

async function setFilters(next: BugFilterState) {
  filters.value = cloneBugFilterState(next)
  await router.replace({ query: filtersToRouteQuery(filters.value, 'list') })
  await load()
}

async function goToPage(page: number) {
  if (!meta.value || page < 1 || page > meta.value.last_page || page === meta.value.current_page) return
  const next = cloneBugFilterState(filters.value)
  next.page = page
  await setFilters(next)
}

async function loadTrackingOptions() {
  const version = ++optionsVersion
  const projects = [...workspace.projects.value]
  if (!projects.length) {
    trackingValues.value = []
    return
  }
  optionsLoading.value = true
  optionsError.value = ''
  try {
    const responses = await Promise.all(projects.map((project) => bugsApi.trackingValues(project.key)))
    if (version !== optionsVersion) return
    trackingValues.value = [...new Map(responses.flatMap((response) => response.data).map((value) => [value.id, value])).values()]
  } catch (caught) {
    if (version === optionsVersion) optionsError.value = bugErrorMessage(caught)
  } finally {
    if (version === optionsVersion) optionsLoading.value = false
  }
}

watch(() => workspace.projects.value.map((project) => project.key).join('|'), () => { void loadTrackingOptions() }, { immediate: true })
void load()
</script>

<template>
  <main class="admin-page discovery-page">
    <header class="admin-header">
      <div>
        <p class="eyebrow">کشف و جستجو</p>
        <h1>باگ‌ها</h1>
        <p>همه باگ‌هایی را که در دسترس شماست، جستجو و فیلتر کنید.</p>
      </div>
      <NuxtLink class="admin-link admin-link--primary" to="/bugs/new">ثبت باگ</NuxtLink>
    </header>

    <section class="admin-panel filter-shell">
      <div class="panel-title"><div><h2>جستجو و فیلترها</h2><p class="panel-copy">انتخاب فضای کاری فقط برای نمایش است؛ فیلترهای انتخاب‌شده به‌صورت پارامترهای عادی ارسال می‌شوند.</p></div></div>
      <p v-if="optionsError" class="admin-notice admin-notice--error" role="alert">همه گزینه‌های فیلتر بارگیری نشد: {{ optionsError }}</p>
      <BugFilters :model-value="filters" :projects="workspace.projects.value" :tracking-values="trackingValues" :user-id="session.user.value?.id" :pending="loading" :options-loading="optionsLoading" @apply="setFilters" @reset="setFilters" />
    </section>

    <section class="admin-panel admin-panel--wide discovery-results">
      <div class="panel-title">
        <div><h2>نتایج</h2><p v-if="meta" class="panel-copy">{{ formatNumber(meta.total) }} باگ قابل مشاهده · صفحه {{ formatNumber(meta.current_page) }} از {{ formatNumber(meta.last_page) }}</p></div>
        <button class="admin-link button-reset" :disabled="loading" @click="load">{{ loading ? 'در حال به‌روزرسانی…' : 'به‌روزرسانی' }}</button>
      </div>

      <p v-if="state === 'loading'" class="panel-copy" role="status">در حال بارگیری باگ‌ها…</p>
      <div v-else-if="state === 'error'" class="form-error discovery-error" role="alert"><span>{{ error }}</span><button class="text-button" type="button" @click="load">تلاش مجدد</button></div>
      <div v-else-if="state === 'empty'" class="work-empty"><h3>باگی پیدا نشد</h3><p>فیلترهای فعلی را تغییر دهید. باگ‌های خارج از دسترس هرگز نمایش داده نمی‌شوند.</p></div>
      <template v-else>
        <div class="admin-table-wrap">
          <table class="admin-table bug-list-table">
            <thead><tr><th>باگ</th><th>پروژه</th><th>وضعیت</th><th>اولویت</th><th>شدت</th><th>مسئول</th><th>آخرین به‌روزرسانی</th></tr></thead>
            <tbody>
              <tr v-for="bug in bugs" :key="bug.id">
                <td><NuxtLink class="work-bug-link" dir="ltr" :to="`/bugs/${encodeURIComponent(bug.public_id)}`">{{ bug.public_id }}</NuxtLink><small>{{ bug.title }}</small></td>
                <td>{{ bug.project.name }}<small dir="ltr">{{ bug.project.key }}</small></td>
                <td><span :class="['bug-status', `bug-status--${bug.status}`]">{{ statusLabel(bug.status) }}</span></td>
                <td><span :class="['classification-chip', 'classification-chip--priority', { 'classification-chip--unset': !bug.priority }]">{{ bug.priority?.name || 'تعیین‌نشده' }}</span></td>
                <td><span :class="['classification-chip', 'classification-chip--severity', { 'classification-chip--unset': !bug.severity }]">{{ bug.severity?.name || 'تعیین‌نشده' }}</span></td>
                <td><span :class="['assignment-label', { 'assignment-label--unassigned': !bug.assignee }]">{{ bug.assignee?.display_name || 'بدون مسئول' }}</span></td>
                <td>{{ formatDateTime(bug.updated_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <nav v-if="meta && meta.last_page > 1" class="pagination" aria-label="صفحه‌های نتایج باگ">
          <button class="button button-secondary" :disabled="loading || meta.current_page <= 1" @click="goToPage(meta.current_page - 1)">قبلی</button>
          <span>صفحه {{ formatNumber(meta.current_page) }} از {{ formatNumber(meta.last_page) }}</span>
          <button class="button button-secondary" :disabled="loading || meta.current_page >= meta.last_page" @click="goToPage(meta.current_page + 1)">بعدی</button>
        </nav>
      </template>
    </section>
  </main>
</template>
