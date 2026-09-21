<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import DataStateRegion from '~/components/shared/DataStateRegion.vue'
import ProjectIdentity from '~/components/shared/ProjectIdentity.vue'
import UserIdentity from '~/components/shared/UserIdentity.vue'
import {
  bugStatuses,
  cloneBugFilterState,
  filtersToRouteQuery,
  type BugFilterState,
  type BugStatusFilter,
  type DashboardCounts,
  type DashboardData,
  type DeveloperBreakdown,
  type ProjectBreakdown,
  type TrackingBreakdown,
} from '~/composables/useBugFilters'
import { formatNumber, resolutionLabel, specialDisplayLabel } from '~/utils/presentation'

const props = defineProps<{
  summary: DashboardData | null
  loading: boolean
  error: string
  filters: BugFilterState
  currentUserId?: string
  filtered?: boolean
}>()

const emit = defineEmits<{ retry: []; clear: [] }>()

const countCells: { key: keyof DashboardCounts; label: string; statuses?: BugStatusFilter[]; description?: string; assignedToMe?: boolean }[] = [
  { key: 'total', label: 'کل باگ‌ها' },
  { key: 'open', label: 'باز', statuses: bugStatuses.filter((status) => status !== 'closed'), description: 'همهٔ وضعیت‌ها به‌جز بسته‌شده' },
  { key: 'awaiting_review', label: 'در انتظار بررسی', statuses: ['submitted', 'review'] },
  { key: 'awaiting_qa', label: 'در انتظار QA', statuses: ['resolved', 'qa_verification'] },
  { key: 'assigned_to_current_developer', label: 'تخصیص‌یافته به من', statuses: bugStatuses.filter((status) => status !== 'closed'), assignedToMe: true, description: 'باگ‌های باز تخصیص‌یافته به حساب فعلی؛ با صف توسعه‌دهنده یکسان نیست.' },
  { key: 'in_progress', label: 'در حال انجام', statuses: ['in_progress'] },
  { key: 'resolved', label: 'رفع‌شده', statuses: ['resolved'] },
  { key: 'qa_verification', label: 'بررسی QA', statuses: ['qa_verification'] },
  { key: 'reopened', label: 'بازگشایی‌شده', statuses: ['reopened'] },
  { key: 'closed', label: 'بسته‌شده', statuses: ['closed'] },
]

const state = computed<'loading' | 'ready' | 'empty' | 'error'>(() => {
  if (props.loading) return 'loading'
  if (props.error) return 'error'
  if (!props.summary || props.summary.counts.total === 0) return 'empty'
  return 'ready'
})

function duration(milliseconds: number): string {
  const totalMinutes = Math.max(0, Math.round(milliseconds / 60_000))
  if (totalMinutes < 1) return 'کمتر از ۱ دقیقه'
  const days = Math.floor(totalMinutes / 1440)
  const hours = Math.floor((totalMinutes % 1440) / 60)
  const minutes = totalMinutes % 60
  return [[days, 'روز'], [hours, 'ساعت'], [minutes, 'دقیقه']]
    .filter(([value]) => Number(value) > 0)
    .slice(0, 2)
    .map(([value, unit]) => `${formatNumber(Number(value))} ${unit}`)
    .join(' ')
}

function toList(filters: BugFilterState) {
  return { path: '/bugs', query: filtersToRouteQuery(filters, 'dashboard') }
}

function metricTo(cell: typeof countCells[number]) {
  if (!props.summary || props.summary.counts[cell.key] === 0) return null
  const next = cloneBugFilterState(props.filters)
  if (cell.statuses) {
    const intersection = next.status.length ? next.status.filter((status) => cell.statuses!.includes(status)) : cell.statuses
    if (!intersection.length) return null
    next.status = [...intersection]
  }
  if (cell.assignedToMe) {
    if (!props.currentUserId) return null
    next.assignee = [props.currentUserId]
  }
  next.page = 1
  return toList(next)
}

function breakdownTo(dimension: 'severity' | 'category' | 'project' | 'assignee', value: string, count: number) {
  if (count === 0) return null
  const next = cloneBugFilterState(props.filters)
  next[dimension] = [value]
  next.page = 1
  return toList(next)
}

function sortRows<T extends { count: number }>(rows: readonly T[], label: (row: T) => string) {
  return [...rows].sort((left, right) => right.count - left.count || label(left).localeCompare(label(right), 'fa'))
}

const severityRows = computed(() => sortRows(props.summary?.breakdowns.severity || [], (row) => row.name))
const categoryRows = computed(() => sortRows(props.summary?.breakdowns.category || [], (row) => row.name))
const projectRows = computed(() => sortRows(props.summary?.breakdowns.project || [], (row) => row.name))
const developerRows = computed(() => sortRows(props.summary?.breakdowns.developer || [], (row) => row.display_name))

function trackingLink(dimension: 'severity' | 'category', row: TrackingBreakdown) {
  return row.id ? breakdownTo(dimension, row.id, row.count) : null
}

function projectLink(row: ProjectBreakdown) {
  return breakdownTo('project', row.key, row.count)
}

function developerLink(row: DeveloperBreakdown) {
  return breakdownTo('assignee', row.id || 'unassigned', row.count)
}
</script>

<template>
  <DataStateRegion :state="state" label="خلاصهٔ داشبورد" :busy="loading" class="dashboard-results">
    <template #loading>
      <div class="dashboard-loading" role="status" aria-live="polite">
        <span class="sr-only">در حال بارگیری داشبورد…</span>
        <div class="dashboard-ledger dashboard-ledger--skeleton"><USkeleton v-for="index in 10" :key="index" class="h-16 w-full" /></div>
        <USkeleton class="h-20 w-full" />
        <div class="dashboard-breakdown-matrix dashboard-breakdown-matrix--skeleton"><USkeleton v-for="index in 4" :key="index" class="h-32 w-full" /></div>
      </div>
    </template>

    <template #error>
      <UAlert color="error" variant="subtle" icon="i-lucide-circle-alert" title="بارگیری داشبورد انجام نشد" :description="error">
        <template #actions><UButton color="error" variant="soft" size="sm" :disabled="loading" @click="emit('retry')">تلاش مجدد</UButton></template>
      </UAlert>
    </template>

    <template #empty>
      <UAlert
        v-if="filtered"
        color="neutral"
        variant="subtle"
        icon="i-lucide-search-x"
        title="باگی با این جستجو و فیلترها مطابقت ندارد."
      >
        <template #actions>
          <UButton color="neutral" variant="soft" size="sm" @click="emit('clear')">پاک‌کردن فیلترها</UButton>
          <UButton :to="toList(filters)" color="neutral" variant="ghost" size="sm">نمایش فهرست باگ‌ها</UButton>
        </template>
      </UAlert>
      <UAlert v-else color="neutral" variant="subtle" icon="i-lucide-inbox" title="هنوز باگی برای نمایش در داشبورد وجود ندارد." />
    </template>

    <template #default>
      <section class="dashboard-ledger" aria-label="شمارش وضعیت‌ها">
        <component :is="metricTo(cell) ? RouterLink : 'div'" v-for="cell in countCells" :key="cell.key" :to="metricTo(cell) || undefined" :class="['dashboard-ledger__cell', { 'dashboard-ledger__cell--linked': metricTo(cell) }]" :title="cell.description">
          <span>{{ cell.label }}</span>
          <strong><bdi dir="ltr">{{ formatNumber(summary!.counts[cell.key]) }}</bdi></strong>
          <UIcon v-if="metricTo(cell)" name="i-lucide-arrow-up-left" class="dashboard-ledger__link-icon" aria-hidden="true" />
        </component>
      </section>

      <section class="dashboard-resolution" aria-labelledby="resolution-time-heading">
        <div><h2 id="resolution-time-heading">میانگین زمان ثبت نتیجه</h2><p>از ایجاد تا آخرین نتیجهٔ ثبت‌شده؛ سنجش زمان تأیید رفع نیست.</p></div>
        <div class="dashboard-resolution__value">
          <strong v-if="summary!.average_resolution_time.sample_count && summary!.average_resolution_time.milliseconds !== null"><bdi dir="ltr">{{ duration(summary!.average_resolution_time.milliseconds) }}</bdi></strong>
          <strong v-else>داده‌ای موجود نیست</strong>
          <span v-if="summary!.average_resolution_time.sample_count"><bdi dir="ltr">{{ formatNumber(summary!.average_resolution_time.sample_count) }}</bdi> نمونه</span>
          <span v-else>نمونهٔ واجد شرایط وجود ندارد</span>
        </div>
        <p class="dashboard-resolution__outcomes">نتیجه‌های لحاظ‌شده: {{ summary!.average_resolution_time.included_outcomes.map(resolutionLabel).join('، ') }}</p>
      </section>

      <section class="dashboard-breakdown-matrix" aria-label="تفکیک باگ‌ها">
        <article class="dashboard-breakdown"><h2>شدت</h2><ul><li v-for="row in severityRows" :key="row.id || 'unset'"><component :is="trackingLink('severity', row) ? RouterLink : 'span'" :to="trackingLink('severity', row) || undefined" :class="{ 'dashboard-breakdown__link': trackingLink('severity', row) }">{{ specialDisplayLabel(row.name) }}<UIcon v-if="trackingLink('severity', row)" name="i-lucide-arrow-up-left" /></component><strong><bdi dir="ltr">{{ formatNumber(row.count) }}</bdi></strong></li></ul></article>
        <article class="dashboard-breakdown"><h2>دسته‌بندی</h2><ul><li v-for="row in categoryRows" :key="row.id || 'unset'"><component :is="trackingLink('category', row) ? RouterLink : 'span'" :to="trackingLink('category', row) || undefined" :class="{ 'dashboard-breakdown__link': trackingLink('category', row) }">{{ specialDisplayLabel(row.name) }}<UIcon v-if="trackingLink('category', row)" name="i-lucide-arrow-up-left" /></component><strong><bdi dir="ltr">{{ formatNumber(row.count) }}</bdi></strong></li></ul></article>
        <article class="dashboard-breakdown"><h2>پروژه</h2><ul><li v-for="row in projectRows" :key="row.id"><RouterLink :to="projectLink(row)!" class="dashboard-breakdown__link"><ProjectIdentity :name="row.name" :project-key="row.key" /><UIcon name="i-lucide-arrow-up-left" /></RouterLink><strong><bdi dir="ltr">{{ formatNumber(row.count) }}</bdi></strong></li></ul></article>
        <article class="dashboard-breakdown"><h2>توسعه‌دهنده</h2><ul><li v-for="row in developerRows" :key="row.id || 'unassigned'"><RouterLink :to="developerLink(row)!" class="dashboard-breakdown__link"><UserIdentity v-if="row.id" :name="row.display_name" :user-id="row.id" compact /><span v-else>{{ specialDisplayLabel(row.display_name) }}</span><UIcon name="i-lucide-arrow-up-left" /></RouterLink><strong><bdi dir="ltr">{{ formatNumber(row.count) }}</bdi></strong></li></ul></article>
      </section>
    </template>
  </DataStateRegion>
</template>

<style scoped>
.dashboard-results, .dashboard-loading { display: grid; gap: 1rem; }
.dashboard-loading { grid-template-columns: 1fr; }
.dashboard-ledger { border: 1px solid var(--ui-border); border-radius: .75rem; display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); overflow: hidden; }
.dashboard-ledger__cell { display: grid; gap: .35rem; min-height: 5.5rem; padding: 1rem; position: relative; }
.dashboard-ledger__cell:nth-child(n + 6) { border-top: 1px solid var(--ui-border); }
.dashboard-ledger__cell:not(:nth-child(5n + 1)) { border-inline-start: 1px solid var(--ui-border); }
.dashboard-ledger__cell > span { color: var(--ui-text-muted); font-size: .75rem; }
.dashboard-ledger__cell strong { font-size: 1.45rem; font-variant-numeric: tabular-nums; }
.dashboard-ledger__cell--linked { color: inherit; text-decoration: none; transition: background-color .15s ease; }
.dashboard-ledger__cell--linked:hover, .dashboard-ledger__cell--linked:focus-visible { background: var(--ui-bg-elevated); outline: none; }
.dashboard-ledger__link-icon { color: var(--ui-text-dimmed); inset-inline-end: .75rem; position: absolute; top: .75rem; }
.dashboard-ledger--skeleton { padding: .5rem; }
.dashboard-resolution { border: 1px solid var(--ui-border); border-radius: .75rem; display: grid; gap: 1rem; grid-template-columns: minmax(0, 1fr) auto; padding: 1rem; }
.dashboard-resolution h2, .dashboard-breakdown h2 { font-size: .875rem; margin: 0; }
.dashboard-resolution p { color: var(--ui-text-muted); font-size: .75rem; line-height: 1.6; margin: .35rem 0 0; }
.dashboard-resolution__value { display: grid; gap: .2rem; text-align: end; }
.dashboard-resolution__value strong { color: var(--ui-primary); font-size: 1.35rem; }
.dashboard-resolution__value span, .dashboard-resolution__outcomes { color: var(--ui-text-dimmed); font-size: .72rem; }
.dashboard-resolution__outcomes { border-top: 1px solid var(--ui-border); grid-column: 1 / -1; padding-top: .75rem; }
.dashboard-breakdown-matrix { display: grid; gap: 1rem 1.5rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
.dashboard-breakdown { border-top: 1px solid var(--ui-border); min-width: 0; padding-top: .85rem; }
.dashboard-breakdown ul { list-style: none; margin: .65rem 0 0; padding: 0; }
.dashboard-breakdown li { align-items: center; border-top: 1px solid var(--ui-border-muted); display: flex; gap: 1rem; justify-content: space-between; min-height: 2.6rem; }
.dashboard-breakdown li > :first-child { min-width: 0; overflow-wrap: anywhere; }
.dashboard-breakdown strong { font-size: .8rem; font-variant-numeric: tabular-nums; }
.dashboard-breakdown__link { align-items: center; color: var(--ui-text); display: inline-flex; gap: .35rem; min-width: 0; text-decoration: none; }
.dashboard-breakdown__link:hover, .dashboard-breakdown__link:focus-visible { color: var(--ui-primary); outline: none; text-decoration: underline; text-underline-offset: .18rem; }
.dashboard-breakdown__link :deep(svg) { color: var(--ui-text-dimmed); flex: 0 0 auto; height: .85rem; width: .85rem; }
.dashboard-breakdown-matrix--skeleton { grid-template-columns: repeat(2, minmax(0, 1fr)); }

@media (max-width: 79.9rem) {
  .dashboard-ledger { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .dashboard-ledger__cell:nth-child(n) { border-inline-start: 1px solid var(--ui-border); border-top: 1px solid var(--ui-border); }
  .dashboard-ledger__cell:nth-child(-n + 3) { border-top: 0; }
  .dashboard-ledger__cell:nth-child(3n + 1) { border-inline-start: 0; }
}
@media (max-width: 48rem) {
  .dashboard-ledger { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .dashboard-ledger__cell:nth-child(-n + 3) { border-top: 1px solid var(--ui-border); }
  .dashboard-ledger__cell:nth-child(-n + 2) { border-top: 0; }
  .dashboard-ledger__cell:nth-child(3n + 1) { border-inline-start: 1px solid var(--ui-border); }
  .dashboard-ledger__cell:nth-child(odd) { border-inline-start: 0; }
  .dashboard-resolution, .dashboard-breakdown-matrix { grid-template-columns: 1fr; }
  .dashboard-resolution__value { text-align: start; }
  .dashboard-breakdown-matrix--skeleton { grid-template-columns: 1fr; }
}
</style>
