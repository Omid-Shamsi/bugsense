<script setup lang="ts">
import BugAdvancedFilters from '~/components/bugs/BugAdvancedFilters.vue'
import type { BugTrackingValue } from '~/composables/useBugs'
import { bugStatuses, cloneBugFilterState, createBugFilterState, hasActiveBugQuery, type BugFilterState, type BugSort, type PaginationMeta } from '~/composables/useBugFilters'
import type { VisibleProject } from '~/composables/useWorkspace'
import { formatNumber, statusLabel } from '~/utils/presentation'

const props = withDefaults(defineProps<{
  modelValue: BugFilterState
  projects: readonly VisibleProject[]
  trackingValues: readonly BugTrackingValue[]
  trackingLoading?: boolean
  trackingError?: string
  disableTrackingOnError?: boolean
  userId?: string
  pending?: boolean
  mode?: 'list' | 'dashboard'
  meta?: PaginationMeta | null
}>(), {
  trackingLoading: false,
  trackingError: '',
  disableTrackingOnError: false,
  userId: '',
  pending: false,
  mode: 'list',
  meta: null,
})

const emit = defineEmits<{
  apply: [state: BugFilterState]
  reset: [state: BugFilterState]
  refresh: []
  retryOptions: []
}>()

const draft = reactive<BugFilterState>(createBugFilterState())
const advancedOpenDesktop = ref(false)
const advancedOpenMobile = ref(false)

watch(() => props.modelValue, (value) => Object.assign(draft, cloneBugFilterState(value)), { immediate: true, deep: true })

const projectOptions = computed(() => props.projects.map((project) => ({ id: project.key, label: project.name, description: project.key })))
const statusOptions = computed(() => bugStatuses.map((status) => ({ id: status, label: statusLabel(status) })))
const sortOptions: { id: BugSort; label: string }[] = [
  { id: 'updated_at', label: 'آخرین به‌روزرسانی' },
  { id: 'created_at', label: 'زمان ایجاد' },
  { id: 'priority', label: 'رتبه اولویت' },
  { id: 'severity', label: 'رتبه شدت' },
  { id: 'public_id', label: 'شناسه باگ' },
]
const pageSizeOptions = [25, 50, 100].map((value) => ({ id: value, label: String(value) }))

const dateDimensionKeys = ['created_from', 'created_to', 'updated_from', 'updated_to'] as const

const activeDimensionCount = computed(() => {
  let count = 0
  for (const key of ['priority', 'severity', 'category', 'tag', 'reporter', 'assignee'] as const) {
    if (props.modelValue[key].length) count++
  }
  for (const key of dateDimensionKeys) {
    if (props.modelValue[key]) count++
  }
  return count
})

const hasActiveQuery = computed(() => hasActiveBugQuery(props.modelValue))

const trackingValueName = computed(() => {
  const map = new Map<string, string>()
  for (const value of props.trackingValues) {
    const project = props.projects.find((item) => item.id === value.project_id)
    map.set(`${value.kind}:${value.id}`, project?.key ? `${project.key} · ${value.name}` : value.name)
  }
  return map
})

interface Chip { key: string; label: string; remove: () => void }

function applyFromAppliedBase(mutate: (next: BugFilterState) => void) {
  const next = cloneBugFilterState(props.modelValue)
  mutate(next)
  next.page = 1
  emit('apply', next)
}

const chips = computed<Chip[]>(() => {
  const list: Chip[] = []
  const applied = props.modelValue

  if (applied.q.trim()) {
    list.push({ key: 'q', label: `جستجو: «${applied.q.trim()}»`, remove: () => applyFromAppliedBase((next) => { next.q = '' }) })
  }
  for (const key of applied.project) {
    const project = props.projects.find((item) => item.key === key)
    list.push({ key: `project:${key}`, label: `پروژه: ${project?.name || key}`, remove: () => applyFromAppliedBase((next) => { next.project = next.project.filter((v) => v !== key) }) })
  }
  for (const status of applied.status) {
    list.push({ key: `status:${status}`, label: `وضعیت: ${statusLabel(status)}`, remove: () => applyFromAppliedBase((next) => { next.status = next.status.filter((v) => v !== status) }) })
  }
  for (const kind of ['priority', 'severity', 'category', 'tag'] as const) {
    for (const id of applied[kind]) {
      list.push({
        key: `${kind}:${id}`,
        label: trackingValueName.value.get(`${kind}:${id}`) || id,
        remove: () => applyFromAppliedBase((next) => { next[kind] = next[kind].filter((v) => v !== id) }),
      })
    }
  }
  for (const id of applied.reporter) {
    list.push({ key: `reporter:${id}`, label: `گزارش‌دهنده: ${id === props.userId ? 'من' : id}`, remove: () => applyFromAppliedBase((next) => { next.reporter = next.reporter.filter((v) => v !== id) }) })
  }
  for (const id of applied.assignee) {
    list.push({ key: `assignee:${id}`, label: `مسئول: ${id === 'unassigned' ? 'بدون مسئول' : id === props.userId ? 'من' : id}`, remove: () => applyFromAppliedBase((next) => { next.assignee = next.assignee.filter((v) => v !== id) }) })
  }
  const dateLabels: Record<typeof dateDimensionKeys[number], string> = { created_from: 'ایجادشده از', created_to: 'ایجادشده تا', updated_from: 'به‌روزشده از', updated_to: 'به‌روزشده تا' }
  for (const key of dateDimensionKeys) {
    if (applied[key]) list.push({ key, label: `${dateLabels[key]}: ${applied[key]}`, remove: () => applyFromAppliedBase((next) => { next[key] = '' }) })
  }
  return list
})

function applyDraft() {
  const next = cloneBugFilterState(draft)
  next.page = 1
  advancedOpenDesktop.value = false
  advancedOpenMobile.value = false
  emit('apply', next)
}

function resetAll() {
  const next = createBugFilterState()
  advancedOpenDesktop.value = false
  advancedOpenMobile.value = false
  emit('reset', next)
}

function setSort(sort: BugSort | null) {
  if (!sort || sort === props.modelValue.sort) return
  applyFromAppliedBase((next) => { next.sort = sort })
}

function toggleDirection() {
  applyFromAppliedBase((next) => { next.direction = next.direction === 'asc' ? 'desc' : 'asc' })
}

function setPageSize(value: number | null) {
  if (value == null || value === props.modelValue.per_page) return
  applyFromAppliedBase((next) => { next.per_page = value })
}

const hasRange = computed(() => !!props.meta && props.meta.total > 0)
const rangeFromTo = computed(() => {
  const meta = props.meta
  if (!meta || meta.total === 0) return ''
  const from = meta.from ?? (meta.current_page - 1) * meta.per_page + 1
  const to = meta.to ?? Math.min(meta.current_page * meta.per_page, meta.total)
  return `${formatNumber(from)}–${formatNumber(to)}`
})
const totalText = computed(() => formatNumber(props.meta?.total ?? 0))
</script>

<template>
  <div class="bug-query-toolbar">
    <form class="bug-query-toolbar__row" novalidate @submit.prevent="applyDraft">
      <UFormField class="bug-query-toolbar__search" :ui="{ container: 'w-full' }">
        <template #label><span id="bug-query-search-label" class="sr-only">جستجو</span></template>
        <UInput
          v-model="draft.q"
          aria-labelledby="bug-query-search-label"
          icon="i-lucide-search"
          dir="auto"
          maxlength="200"
          placeholder="شناسه باگ، عنوان یا توضیحات"
          :disabled="pending"
          class="w-full"
        />
      </UFormField>

      <UFormField class="bug-query-toolbar__project" :ui="{ container: 'w-full' }">
        <template #label><span id="bug-query-project-label" class="sr-only">پروژه</span></template>
        <USelectMenu
          v-model="draft.project"
          aria-labelledby="bug-query-project-label"
          multiple
          :items="projectOptions"
          value-key="id"
          label-key="label"
          :disabled="pending"
          placeholder="همهٔ پروژه‌ها"
          :search-input="projects.length > 8"
          class="w-full"
        />
      </UFormField>

      <UFormField class="bug-query-toolbar__status" :ui="{ container: 'w-full' }">
        <template #label><span id="bug-query-status-label" class="sr-only">وضعیت</span></template>
        <USelectMenu
          v-model="draft.status"
          aria-labelledby="bug-query-status-label"
          multiple
          :items="statusOptions"
          value-key="id"
          label-key="label"
          :disabled="pending"
          placeholder="همهٔ وضعیت‌ها"
          class="w-full"
        />
      </UFormField>

      <UPopover v-model:open="advancedOpenDesktop" class="bug-query-toolbar__advanced-trigger bug-query-toolbar__advanced-trigger--desktop" :content="{ align: 'end', side: 'bottom' }">
        <UButton color="neutral" variant="outline" icon="i-lucide-sliders-horizontal" :disabled="pending">
          فیلترها
          <UBadge v-if="activeDimensionCount" color="primary" variant="subtle" size="xs">{{ activeDimensionCount }}</UBadge>
        </UButton>
        <template #content>
          <div class="bug-query-toolbar__advanced-panel">
            <BugAdvancedFilters v-model="draft" :projects="projects" :tracking-values="trackingValues" :tracking-loading="trackingLoading" :tracking-error="trackingError" :disable-tracking-on-error="disableTrackingOnError" :user-id="userId" :pending="pending" @retry-options="emit('retryOptions')" />
            <div class="bug-query-toolbar__advanced-actions">
              <UButton type="submit" size="sm" :disabled="pending" @click="applyDraft">اعمال</UButton>
              <UButton color="neutral" variant="ghost" size="sm" :disabled="pending" @click="advancedOpenDesktop = false">بستن</UButton>
            </div>
          </div>
        </template>
      </UPopover>

      <UButton class="bug-query-toolbar__advanced-trigger bug-query-toolbar__advanced-trigger--mobile" color="neutral" variant="outline" icon="i-lucide-sliders-horizontal" :disabled="pending" @click="advancedOpenMobile = true">
        فیلترها
        <UBadge v-if="activeDimensionCount" color="primary" variant="subtle" size="xs">{{ activeDimensionCount }}</UBadge>
      </UButton>

      <UButton type="submit" icon="i-lucide-check" :loading="pending" :disabled="pending">اعمال</UButton>
    </form>

    <USlideover v-model:open="advancedOpenMobile" side="right" title="فیلترهای پیشرفته">
      <template #body>
        <BugAdvancedFilters v-model="draft" :projects="projects" :tracking-values="trackingValues" :tracking-loading="trackingLoading" :tracking-error="trackingError" :disable-tracking-on-error="disableTrackingOnError" :user-id="userId" :pending="pending" @retry-options="emit('retryOptions')" />
      </template>
      <template #footer>
        <UButton block :disabled="pending" @click="applyDraft">اعمال</UButton>
      </template>
    </USlideover>

    <div class="bug-query-toolbar__strip">
      <div class="bug-query-toolbar__chips">
        <slot v-if="mode === 'dashboard'" name="dashboard-scope" />
        <UButton v-if="hasActiveQuery" color="neutral" variant="ghost" size="xs" :disabled="pending" @click="resetAll">پاک‌کردن همه</UButton>
        <UBadge v-for="chip in chips" :key="chip.key" color="neutral" variant="subtle" size="sm" class="bug-query-toolbar__chip">
          <span dir="auto">{{ chip.label }}</span>
          <button type="button" class="bug-query-toolbar__chip-remove" :aria-label="`حذف فیلتر ${chip.label}`" :disabled="pending" @click="chip.remove">
            <UIcon name="i-lucide-x" />
          </button>
        </UBadge>
      </div>

      <div v-if="mode === 'dashboard'" class="bug-query-toolbar__controls"><slot name="dashboard-actions" /></div>
      <div v-if="mode === 'list'" class="bug-query-toolbar__controls">
        <span v-if="meta" class="bug-query-toolbar__range" role="status">
          <template v-if="hasRange"><bdi dir="ltr">{{ rangeFromTo }}</bdi> از <bdi dir="ltr">{{ totalText }}</bdi> باگ</template>
          <template v-else>{{ totalText }} باگ</template>
        </span>

        <label class="bug-query-toolbar__control">
          <span id="bug-query-sort-label" class="sr-only">مرتب‌سازی بر اساس</span>
          <USelectMenu :model-value="modelValue.sort" aria-labelledby="bug-query-sort-label" :items="sortOptions" value-key="id" label-key="label" :disabled="pending" :search-input="false" @update:model-value="setSort" />
        </label>

        <UButton
          color="neutral"
          variant="outline"
          size="sm"
          :icon="modelValue.direction === 'asc' ? 'i-lucide-arrow-up' : 'i-lucide-arrow-down'"
          :disabled="pending"
          :aria-label="modelValue.direction === 'asc' ? 'صعودی؛ برای نزولی تغییر دهید' : 'نزولی؛ برای صعودی تغییر دهید'"
          @click="toggleDirection"
        />

        <label class="bug-query-toolbar__control">
          <span id="bug-query-pagesize-label" class="sr-only">تعداد در صفحه</span>
          <USelectMenu :model-value="modelValue.per_page" aria-labelledby="bug-query-pagesize-label" :items="pageSizeOptions" value-key="id" label-key="label" :disabled="pending" :search-input="false" @update:model-value="setPageSize" />
        </label>

        <UButton color="neutral" variant="ghost" size="sm" icon="i-lucide-refresh-cw" :loading="pending" :disabled="pending" @click="emit('refresh')">به‌روزرسانی</UButton>
      </div>
    </div>
  </div>
</template>

<style scoped>
.bug-query-toolbar { border-bottom: 1px solid var(--ui-border); display: grid; gap: .75rem; padding-block: .85rem; }
.bug-query-toolbar__row { align-items: end; display: flex; flex-wrap: wrap; gap: .6rem; }
.bug-query-toolbar__search { flex: 1 1 20rem; min-width: 14rem; }
.bug-query-toolbar__project, .bug-query-toolbar__status { flex: 0 1 13rem; min-width: 9rem; }
.bug-query-toolbar__advanced-panel { display: grid; gap: 1rem; max-width: min(90vw, 34rem); padding: .5rem; }
.bug-query-toolbar__advanced-actions { border-top: 1px solid var(--ui-border); display: flex; gap: .5rem; justify-content: flex-start; padding-top: .75rem; }
.bug-query-toolbar__advanced-trigger--mobile { display: none; }
.bug-query-toolbar__strip { align-items: center; display: flex; flex-wrap: wrap; gap: .75rem; justify-content: space-between; }
.bug-query-toolbar__chips { align-items: center; display: flex; flex-wrap: wrap; gap: .4rem; max-height: 3.4rem; overflow: hidden; }
.bug-query-toolbar__chip { align-items: center; display: inline-flex; gap: .3rem; }
.bug-query-toolbar__chip-remove { align-items: center; display: inline-flex; }
.bug-query-toolbar__controls { align-items: center; display: flex; flex-wrap: wrap; gap: .5rem; }
.bug-query-toolbar__control :deep(button) { min-width: 8.5rem; }
.bug-query-toolbar__range { color: var(--ui-text-muted); font-size: .78rem; white-space: nowrap; }
@media (max-width: 61.9rem) {
  .bug-query-toolbar__advanced-trigger--desktop { display: none; }
  .bug-query-toolbar__advanced-trigger--mobile { display: inline-flex; }
}
@media (max-width: 48rem) {
  .bug-query-toolbar__row { flex-direction: column; align-items: stretch; }
  .bug-query-toolbar__search, .bug-query-toolbar__project, .bug-query-toolbar__status { flex-basis: auto; }
  .bug-query-toolbar__control :deep(button) { min-width: 0; width: 100%; }
}
</style>
