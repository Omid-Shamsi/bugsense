<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { BugTrackingValue } from '~/composables/useBugs'
import { cloneBugFilterState, createBugFilterState, type BugFilterState, type BugStatusFilter } from '~/composables/useBugFilters'
import type { VisibleProject } from '~/composables/useWorkspace'
import { statusLabel } from '~/utils/presentation'

const props = withDefaults(defineProps<{
  modelValue: BugFilterState
  projects: readonly VisibleProject[]
  trackingValues: readonly BugTrackingValue[]
  userId?: string
  listMode?: boolean
  pending?: boolean
  optionsLoading?: boolean
}>(), {
  userId: '',
  listMode: true,
  pending: false,
  optionsLoading: false,
})

const emit = defineEmits<{
  apply: [filters: BugFilterState]
  reset: [filters: BugFilterState]
}>()

const draft = reactive<BugFilterState>(createBugFilterState())
const statuses: BugStatusFilter[] = ['submitted', 'review', 'needs_information', 'assigned', 'in_progress', 'resolved', 'qa_verification', 'reopened', 'closed']

const projectById = computed(() => new Map(props.projects.map((project) => [project.id, project])))
const valuesByKind = (kind: BugTrackingValue['kind']) => props.trackingValues.filter((value) => value.kind === kind)
const reporterIds = computed({
  get: () => draft.reporter.join(', '),
  set: (value: string) => { draft.reporter = commaSeparatedValues(value) },
})
const assigneeIds = computed({
  get: () => draft.assignee.filter((value) => value !== 'unassigned').join(', '),
  set: (value: string) => {
    const unassigned = draft.assignee.includes('unassigned') ? ['unassigned'] : []
    draft.assignee = [...unassigned, ...commaSeparatedValues(value)]
  },
})

function commaSeparatedValues(value: string): string[] {
  return [...new Set(value.split(',').map((item) => item.trim()).filter(Boolean))]
}

function replaceDraft(value: BugFilterState) {
  Object.assign(draft, cloneBugFilterState(value))
}

watch(() => props.modelValue, replaceDraft, { immediate: true, deep: true })

function hasDimensionValue(dimension: 'reporter' | 'assignee', value: string): boolean {
  return !!value && draft[dimension].includes(value)
}

function setDimensionValue(dimension: 'reporter' | 'assignee', value: string, selected: boolean) {
  if (!value) return
  const next = draft[dimension].filter((item) => item !== value)
  if (selected) next.push(value)
  draft[dimension] = next
}

function optionLabel(value: BugTrackingValue): string {
  const project = projectById.value.get(value.project_id)
  return `${project?.key ? `${project.key} · ` : ''}${value.name}${value.active ? '' : ' (غیرفعال)'}`
}

function apply() {
  const next = cloneBugFilterState(draft)
  next.page = 1
  emit('apply', next)
}

function reset() {
  const next = createBugFilterState()
  replaceDraft(next)
  emit('reset', next)
}
</script>

<template>
  <form class="bug-filter-panel" @submit.prevent="apply">
    <div class="filter-search-row">
      <label class="filter-search" for="bug-filter-search">
        <span>جستجو</span>
        <input id="bug-filter-search" v-model="draft.q" type="search" maxlength="200" placeholder="شناسه عمومی، عنوان یا توضیحات" :disabled="pending">
      </label>
      <button class="button button-primary filter-submit" :disabled="pending">{{ pending ? 'در حال بارگذاری…' : 'اعمال فیلترها' }}</button>
      <button class="button button-secondary filter-reset" type="button" :disabled="pending" @click="reset">بازنشانی</button>
    </div>

    <div class="filter-grid">
      <label>
        <span>پروژه‌ها <small>انتخاب چندگانه</small></span>
        <select v-model="draft.project" multiple size="4" :disabled="pending">
          <option v-for="project in projects" :key="project.id" :value="project.key">{{ project.key }} · {{ project.name }}</option>
        </select>
      </label>

      <label>
        <span>وضعیت <small>انتخاب چندگانه</small></span>
        <select v-model="draft.status" multiple size="4" :disabled="pending">
          <option v-for="status in statuses" :key="status" :value="status">{{ statusLabel(status) }}</option>
        </select>
      </label>

      <label>
        <span>اولویت <small>انتخاب چندگانه</small></span>
        <select v-model="draft.priority" multiple size="4" :disabled="pending || optionsLoading">
          <option v-for="value in valuesByKind('priority')" :key="value.id" :value="value.id">{{ optionLabel(value) }}</option>
        </select>
      </label>

      <label>
        <span>شدت <small>انتخاب چندگانه</small></span>
        <select v-model="draft.severity" multiple size="4" :disabled="pending || optionsLoading">
          <option v-for="value in valuesByKind('severity')" :key="value.id" :value="value.id">{{ optionLabel(value) }}</option>
        </select>
      </label>

      <label>
        <span>دسته‌بندی <small>انتخاب چندگانه</small></span>
        <select v-model="draft.category" multiple size="4" :disabled="pending || optionsLoading">
          <option v-for="value in valuesByKind('category')" :key="value.id" :value="value.id">{{ optionLabel(value) }}</option>
        </select>
      </label>

      <label>
        <span>برچسب‌ها <small>هر برچسب انتخاب‌شده</small></span>
        <select v-model="draft.tag" multiple size="4" :disabled="pending || optionsLoading">
          <option v-for="value in valuesByKind('tag')" :key="value.id" :value="value.id">{{ optionLabel(value) }}</option>
        </select>
      </label>
    </div>

    <div class="filter-checks">
      <label v-if="userId" class="check-row">
        <input type="checkbox" :checked="hasDimensionValue('reporter', userId)" :disabled="pending" @change="setDimensionValue('reporter', userId, ($event.target as HTMLInputElement).checked)">
        گزارش‌شده توسط من
      </label>
      <label v-if="userId" class="check-row">
        <input type="checkbox" :checked="hasDimensionValue('assignee', userId)" :disabled="pending" @change="setDimensionValue('assignee', userId, ($event.target as HTMLInputElement).checked)">
        تخصیص‌یافته به من
      </label>
      <label class="check-row">
        <input type="checkbox" :checked="hasDimensionValue('assignee', 'unassigned')" :disabled="pending" @change="setDimensionValue('assignee', 'unassigned', ($event.target as HTMLInputElement).checked)">
        بدون مسئول
      </label>
    </div>

    <details class="filter-details">
      <summary>بازه‌های زمانی و {{ listMode ? 'مرتب‌سازی' : 'گزینه‌های بیشتر' }}</summary>
      <div class="filter-grid filter-grid--dates">
        <label><span>ایجادشده از</span><input v-model="draft.created_from" type="datetime-local" :disabled="pending"></label>
        <label><span>ایجادشده تا</span><input v-model="draft.created_to" type="datetime-local" :disabled="pending"></label>
        <label><span>به‌روزشده از</span><input v-model="draft.updated_from" type="datetime-local" :disabled="pending"></label>
        <label><span>به‌روزشده تا</span><input v-model="draft.updated_to" type="datetime-local" :disabled="pending"></label>
        <label><span>UUID کاربران گزارش‌دهنده</span><input v-model="reporterIds" dir="ltr" type="text" autocomplete="off" placeholder="UUID های جداشده با ویرگول" :disabled="pending"></label>
        <label><span>UUID کاربران مسئول</span><input v-model="assigneeIds" dir="ltr" type="text" autocomplete="off" placeholder="UUID های جداشده با ویرگول" :disabled="pending"></label>
        <label v-if="listMode"><span>مرتب‌سازی بر اساس</span><select v-model="draft.sort" :disabled="pending"><option value="updated_at">آخرین به‌روزرسانی</option><option value="created_at">زمان ایجاد</option><option value="priority">رتبه اولویت</option><option value="severity">رتبه شدت</option><option value="public_id">شناسه عمومی</option></select></label>
        <label v-if="listMode"><span>جهت</span><select v-model="draft.direction" :disabled="pending"><option value="desc">نزولی</option><option value="asc">صعودی</option></select></label>
        <label v-if="listMode"><span>تعداد در صفحه</span><select v-model.number="draft.per_page" :disabled="pending"><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option></select></label>
      </div>
      <p class="filter-help">ابعاد مختلف در سرور با هم ترکیب می‌شوند. چند مقدار در یک بعد، با هر مقدار انتخاب‌شده مطابقت دارند. شناسه‌های گزارش‌دهنده و مسئول به‌صورت UUID به Laravel ارسال می‌شوند.</p>
    </details>
  </form>
</template>
