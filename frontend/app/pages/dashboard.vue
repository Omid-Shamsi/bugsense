<script setup lang="ts">
import BugFilters from '~/components/bugs/BugFilters.vue'
import DashboardSummary from '~/components/dashboard/DashboardSummary.vue'
import { bugErrorMessage, useBugs, type BugTrackingValue } from '~/composables/useBugs'
import {
  cloneBugFilterState,
  discoveryErrorMessage,
  filtersFromQuery,
  filtersToRouteQuery,
  useBugDiscovery,
  type BugFilterState,
  type DashboardData,
} from '~/composables/useBugFilters'
import { roleLabel } from '~/utils/presentation'

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

const workspaceContext = computed(() => {
  const project = workspace.selectedProject.value
  const roles = workspace.selectedRoles.value.map(roleLabel).join('، ')
  return project ? `${project.name} (${project.key})${roles ? ` · ${roles}` : ''}` : 'همه پروژه‌های قابل دسترسی'
})

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
  <main class="admin-page dashboard-page">
    <header class="admin-header">
      <div><p class="eyebrow">گزارش‌دهی</p><h1>داشبورد</h1><p>خلاصه‌های محاسبه‌شده در سرور برای باگ‌های مجاز و فیلترهای انتخابی.</p><p class="workspace-context">فضای کاری: {{ workspaceContext }}. این انتخاب مجوزهای سرور را تغییر نمی‌دهد.</p></div>
      <NuxtLink class="admin-link" :to="{ path: '/bugs', query: filtersToRouteQuery(filters, 'dashboard') }">مشاهده باگ‌های مطابق</NuxtLink>
    </header>

    <section class="admin-panel filter-shell">
      <div class="panel-title"><div><h2>فیلترهای داشبورد</h2><p class="panel-copy">از همان جستجو و قالب فیلتر فهرست باگ‌ها استفاده می‌کند.</p></div></div>
      <p v-if="optionsError" class="admin-notice admin-notice--error" role="alert">همه گزینه‌های فیلتر بارگیری نشد: {{ optionsError }}</p>
      <BugFilters :model-value="filters" :projects="workspace.projects.value" :tracking-values="trackingValues" :user-id="session.user.value?.id" :list-mode="false" :pending="loading" :options-loading="optionsLoading" @apply="setFilters" @reset="setFilters" />
    </section>

    <DashboardSummary :summary="summary" :loading="loading" :error="error" @retry="load" />
  </main>
</template>
