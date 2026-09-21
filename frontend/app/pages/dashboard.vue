<script setup lang="ts">
import BugQueryToolbar from '~/components/bugs/BugQueryToolbar.vue'
import DashboardSummary from '~/components/dashboard/DashboardSummary.vue'
import { bugErrorMessage, useBugs, type BugTrackingValue } from '~/composables/useBugs'
import {
  cloneBugFilterState,
  createBugFilterState,
  discoveryErrorMessage,
  filtersFromQuery,
  filtersToRouteQuery,
  hasActiveBugQuery,
  useBugDiscovery,
  type BugFilterState,
  type DashboardData,
} from '~/composables/useBugFilters'
import { formatDateTime } from '~/utils/presentation'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const router = useRouter()
const session = useSession()
const workspace = useWorkspace()
const bugsApi = useBugs()
const discovery = useBugDiscovery()
const filters = ref<BugFilterState>(filtersFromQuery(route.query as Record<string, unknown>))
const summary = ref<DashboardData | null>(null)
const trackingValues = ref<BugTrackingValue[]>([])
const loading = ref(true)
const optionsLoading = ref(false)
const error = ref('')
const optionsError = ref('')
let requestVersion = 0
let optionsVersion = 0

const filtered = computed(() => hasActiveBugQuery(filters.value))
const scopeLabel = computed(() => filters.value.project.length ? 'پروژه‌های انتخاب‌شده' : 'همهٔ پروژه‌های قابل دسترسی')

async function load() {
  const version = ++requestVersion
  loading.value = true
  error.value = ''
  try {
    const response = await discovery.dashboard(filters.value)
    if (version === requestVersion) summary.value = response.data
  } catch (caught) {
    if (version === requestVersion) {
      summary.value = null
      error.value = discoveryErrorMessage(caught)
    }
  } finally {
    if (version === requestVersion) loading.value = false
  }
}

async function setFilters(next: BugFilterState) {
  filters.value = cloneBugFilterState(next)
  filters.value.page = 1
  await router.replace({ query: filtersToRouteQuery(filters.value, 'dashboard') })
  await load()
}

function clearFilters() {
  void setFilters(createBugFilterState())
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
  <main class="dashboard-page" :aria-busy="loading">
    <BugQueryToolbar
      v-model="filters"
      mode="dashboard"
      :projects="workspace.projects.value"
      :tracking-values="trackingValues"
      :tracking-loading="optionsLoading"
      :tracking-error="optionsError"
      disable-tracking-on-error
      :user-id="session.user.value?.id"
      :pending="loading"
      @apply="setFilters"
      @reset="setFilters"
      @retry-options="loadTrackingOptions"
    >
      <template #dashboard-scope><span class="dashboard-scope" role="status">{{ scopeLabel }}</span></template>
      <template #dashboard-actions>
        <time v-if="summary" :datetime="summary.context.generated_at" class="dashboard-generated"><UIcon name="i-lucide-clock-3" />{{ formatDateTime(summary.context.generated_at) }}</time>
        <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-refresh-cw" :loading="loading" :disabled="loading" @click="load">به‌روزرسانی</UButton>
        <UButton :to="{ path: '/bugs', query: filtersToRouteQuery(filters, 'dashboard') }" color="neutral" variant="outline" size="sm" icon="i-lucide-list-filter">مشاهده باگ‌های مطابق</UButton>
      </template>
    </BugQueryToolbar>

    <DashboardSummary :summary="summary" :loading="loading" :error="error" :filters="filters" :current-user-id="session.user.value?.id" :filtered="filtered" @retry="load" @clear="clearFilters" />
  </main>
</template>

<style scoped>
.dashboard-page { margin: 0 auto; max-width: 80rem; padding: 0 1.25rem 3rem; }
.dashboard-scope, .dashboard-generated { color: var(--ui-text-dimmed); font-size: .75rem; }
.dashboard-generated { align-items: center; display: inline-flex; gap: .3rem; white-space: nowrap; }
.dashboard-generated :deep(svg) { height: .8rem; width: .8rem; }

@media (max-width: 48rem) {
  .dashboard-page { padding-inline: 1rem; }
  .dashboard-generated { flex-basis: 100%; }
}
</style>
