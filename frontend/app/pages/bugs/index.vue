<script setup lang="ts">
import BugQueryToolbar from '~/components/bugs/BugQueryToolbar.vue'
import { bugErrorMessage, useBugs, type BugReport, type BugTrackingValue } from '~/composables/useBugs'
import {
  cloneBugFilterState,
  discoveryErrorMessage,
  filtersFromQuery,
  filtersToRouteQuery,
  getBugListState,
  hasActiveBugQuery,
  useBugDiscovery,
  type BugFilterState,
  type BugSort,
  type PaginationMeta,
} from '~/composables/useBugFilters'
import { formatDateTime, statusLabel } from '~/utils/presentation'

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
const isFilteredEmpty = computed(() => state.value === 'empty' && hasActiveBugQuery(filters.value))

async function load(): Promise<void> {
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

async function setFilters(next: BugFilterState): Promise<void> {
  filters.value = cloneBugFilterState(next)
  await router.replace({ query: filtersToRouteQuery(filters.value, 'list') })
  await load()
}

async function goToPage(page: number): Promise<void> {
  const next = cloneBugFilterState(filters.value)
  next.page = page
  await setFilters(next)
}

async function changePageSize(pageSize: number): Promise<void> {
  const next = cloneBugFilterState(filters.value)
  next.per_page = pageSize
  next.page = 1
  await setFilters(next)
}

function sortBy(field: BugSort): void {
  const next = cloneBugFilterState(filters.value)
  const isSameField = next.sort === field
  next.direction = isSameField && next.direction === 'desc' ? 'asc' : 'desc'
  next.sort = field
  next.page = 1
  void setFilters(next)
}

async function loadTrackingOptions(): Promise<void> {
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

function sortIcon(field: BugSort): string {
  return filters.value.sort === field ? (filters.value.direction === 'asc' ? 'i-lucide-arrow-up' : 'i-lucide-arrow-down') : ''
}

watch(() => workspace.projects.value.map((project) => project.key).join('|'), () => { void loadTrackingOptions() }, { immediate: true })
void load()
</script>

<template>
  <main class="bugs-list-page" :aria-busy="loading">
    <BugQueryToolbar
      v-model="filters"
      mode="list"
      :projects="workspace.projects.value"
      :tracking-values="trackingValues"
      :tracking-loading="optionsLoading"
      :tracking-error="optionsError"
      :user-id="session.user.value?.id"
      :pending="loading"
      :meta="meta"
      @apply="setFilters"
      @reset="setFilters"
      @refresh="load"
      @retry-options="loadTrackingOptions"
    />

    <DataStateRegion :state="state" label="نتایج باگ‌ها" class="bugs-list-page__region">
      <template #loading>
        <div class="bugs-list-table-wrap" role="status" aria-live="polite">
          <span class="sr-only">در حال بارگیری باگ‌ها…</span>
          <table class="bugs-list-table">
            <thead>
              <tr>
                <th>باگ</th><th class="col-project">پروژه</th><th>وضعیت</th><th class="col-priority">اولویت</th><th class="col-severity">شدت</th><th class="col-assignee">مسئول</th><th>به‌روزرسانی</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="index in 8" :key="index">
                <td><USkeleton class="h-4 w-40" /><USkeleton class="mt-2 h-3 w-20" /></td>
                <td class="col-project"><USkeleton class="h-4 w-28" /></td>
                <td><USkeleton class="h-5 w-20" /></td>
                <td class="col-priority"><USkeleton class="h-5 w-16" /></td>
                <td class="col-severity"><USkeleton class="h-5 w-16" /></td>
                <td class="col-assignee"><USkeleton class="h-4 w-24" /></td>
                <td><USkeleton class="h-4 w-24" /></td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <template #error>
        <div class="bugs-list-page__state">
          <UAlert color="error" variant="subtle" icon="i-lucide-circle-alert" title="بارگیری باگ‌ها ناموفق بود" :description="error">
            <template #actions><UButton color="error" variant="soft" size="sm" @click="load">تلاش مجدد</UButton></template>
          </UAlert>
        </div>
      </template>

      <template #empty>
        <div class="bugs-list-page__state">
          <UAlert
            v-if="isFilteredEmpty"
            color="neutral"
            variant="subtle"
            icon="i-lucide-search-x"
            title="باگی با این جستجو و فیلترها پیدا نشد."
          >
            <template #actions><UButton color="neutral" variant="soft" size="sm" @click="setFilters({ ...filters, q: '', project: [], status: [], severity: [], priority: [], category: [], reporter: [], assignee: [], tag: [], created_from: '', created_to: '', updated_from: '', updated_to: '', page: 1 })">پاک‌کردن فیلترها</UButton></template>
          </UAlert>
          <UAlert v-else color="neutral" variant="subtle" icon="i-lucide-inbox" title="هنوز باگی برای نمایش وجود ندارد." />
        </div>
      </template>

      <div class="bugs-list-table-wrap">
        <table class="bugs-list-table">
          <thead>
            <tr>
              <th>
                <button type="button" class="bugs-list-table__sort" @click="sortBy('public_id')">باگ <UIcon v-if="sortIcon('public_id')" :name="sortIcon('public_id')" /></button>
              </th>
              <th class="col-project">پروژه</th>
              <th>وضعیت</th>
              <th class="col-priority">
                <button type="button" class="bugs-list-table__sort" @click="sortBy('priority')">اولویت <UIcon v-if="sortIcon('priority')" :name="sortIcon('priority')" /></button>
              </th>
              <th class="col-severity">
                <button type="button" class="bugs-list-table__sort" @click="sortBy('severity')">شدت <UIcon v-if="sortIcon('severity')" :name="sortIcon('severity')" /></button>
              </th>
              <th class="col-assignee">مسئول</th>
              <th>
                <button type="button" class="bugs-list-table__sort" @click="sortBy('updated_at')">به‌روزرسانی <UIcon v-if="sortIcon('updated_at')" :name="sortIcon('updated_at')" /></button>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="bug in bugs" :key="bug.id">
              <td>
                <BugIdentityLink :public-id="bug.public_id" :title="bug.title" :to="`/bugs/${encodeURIComponent(bug.public_id)}`" />
                <div class="bugs-list-table__row-meta">
                  <ProjectIdentity :name="bug.project.name" :project-key="bug.project.key" />
                  <span>{{ bug.priority?.name || 'تعیین‌نشده' }}</span>
                  <span>{{ bug.severity?.name || 'تعیین‌نشده' }}</span>
                  <span :class="{ 'bugs-list-table__unassigned': !bug.assignee }">{{ bug.assignee?.display_name || 'بدون مسئول' }}</span>
                </div>
              </td>
              <td class="col-project"><ProjectIdentity :name="bug.project.name" :project-key="bug.project.key" /></td>
              <td><BugStatusBadge :status="bug.status" /></td>
              <td class="col-priority"><UBadge v-if="bug.priority" color="neutral" variant="subtle" size="sm">{{ bug.priority.name }}</UBadge><span v-else class="bugs-list-table__unassigned">تعیین‌نشده</span></td>
              <td class="col-severity"><UBadge v-if="bug.severity" color="neutral" variant="subtle" size="sm">{{ bug.severity.name }}</UBadge><span v-else class="bugs-list-table__unassigned">تعیین‌نشده</span></td>
              <td class="col-assignee"><UserIdentity v-if="bug.assignee" :name="bug.assignee.display_name" compact /><span v-else class="bugs-list-table__unassigned">بدون مسئول</span></td>
              <td><span class="bugs-list-table__updated">{{ formatDateTime(bug.updated_at) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="bugs-list-page__footer">
        <ServerPaginationBar :meta="meta" :pending="loading" :page-size-options="[]" @update:page="goToPage" @update:page-size="changePageSize" />
      </div>
    </DataStateRegion>
  </main>
</template>

<style scoped>
.bugs-list-page { display: grid; }
.bugs-list-page__region { display: grid; gap: 0; }
.bugs-list-page__state { padding: 1.5rem; }
.bugs-list-table-wrap { overflow-x: auto; }
.bugs-list-table { border-collapse: collapse; width: 100%; }
.bugs-list-table thead { background: var(--ui-bg-muted); }
.bugs-list-table th { color: var(--ui-text-muted); font-size: .74rem; font-weight: 600; padding: .6rem .85rem; text-align: start; white-space: nowrap; }
.bugs-list-table td { border-top: 1px solid var(--ui-border); padding: .65rem .85rem; vertical-align: middle; }
.bugs-list-table tbody tr { min-height: 3.5rem; }
.bugs-list-table tbody tr:hover, .bugs-list-table tbody tr:focus-within { background: var(--ui-bg-elevated); }
.bugs-list-table__sort { align-items: center; background: none; border: 0; color: inherit; cursor: pointer; display: inline-flex; font: inherit; gap: .25rem; padding: 0; }
.bugs-list-table__sort:hover { color: var(--ui-text-highlighted); }
.bugs-list-table__unassigned { color: var(--ui-text-dimmed); }
.bugs-list-table__updated { color: var(--ui-text-muted); font-size: .82rem; white-space: nowrap; }
.bugs-list-table__row-meta { display: none; }
.bugs-list-page__footer { border-top: 1px solid var(--ui-border); padding: .85rem; }

@media (max-width: 79.9375rem) {
  .col-priority, .col-severity { display: none; }
}
@media (max-width: 47.9375rem) {
  .col-project, .col-assignee { display: none; }
  .bugs-list-table__row-meta { color: var(--ui-text-muted); display: flex; flex-wrap: wrap; font-size: .72rem; gap: .3rem .6rem; margin-top: .3rem; }
  .bugs-list-table__row-meta > :deep(.project-identity) { font-size: inherit; gap: .25rem; }
}
</style>
