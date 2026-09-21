<script setup lang="ts">
import type { BugTrackingValue } from '~/composables/useBugs'
import { cloneBugFilterState, type BugFilterState } from '~/composables/useBugFilters'
import type { VisibleProject } from '~/composables/useWorkspace'

const props = withDefaults(defineProps<{
  modelValue: BugFilterState
  projects: readonly VisibleProject[]
  trackingValues: readonly BugTrackingValue[]
  trackingLoading?: boolean
  trackingError?: string
  disableTrackingOnError?: boolean
  userId?: string
  pending?: boolean
}>(), {
  trackingLoading: false,
  trackingError: '',
  disableTrackingOnError: false,
  userId: '',
  pending: false,
})

const emit = defineEmits<{
  'update:modelValue': [state: BugFilterState]
  retryOptions: []
}>()

function patch(fn: (draft: BugFilterState) => void) {
  const next = cloneBugFilterState(props.modelValue)
  fn(next)
  emit('update:modelValue', next)
}

function setTracking(dimension: 'priority' | 'severity' | 'category' | 'tag', ids: string[]) {
  patch((draft) => { draft[dimension] = ids })
}

const reportedByMe = computed(() => !!props.userId && props.modelValue.reporter.includes(props.userId))
const assignedToMe = computed(() => !!props.userId && props.modelValue.assignee.includes(props.userId))
const onlyUnassigned = computed(() => props.modelValue.assignee.includes('unassigned'))
const trackingDisabled = computed(() => props.pending || (props.disableTrackingOnError && !!props.trackingError))

function toggleReportedByMe() {
  if (!props.userId) return
  patch((draft) => {
    draft.reporter = reportedByMe.value ? draft.reporter.filter((id) => id !== props.userId) : [...draft.reporter, props.userId!]
  })
}

function toggleAssignedToMe() {
  if (!props.userId) return
  patch((draft) => {
    draft.assignee = assignedToMe.value ? draft.assignee.filter((id) => id !== props.userId) : [...draft.assignee, props.userId!]
  })
}

function toggleUnassigned() {
  patch((draft) => {
    draft.assignee = onlyUnassigned.value ? draft.assignee.filter((id) => id !== 'unassigned') : [...draft.assignee, 'unassigned']
  })
}

const reporterText = computed({
  get: () => props.modelValue.reporter.filter((id) => id !== props.userId).join('، '),
  set: (value: string) => patch((draft) => {
    const keepSelf = draft.reporter.includes(props.userId || '\0') ? [props.userId as string] : []
    draft.reporter = [...new Set([...keepSelf, ...commaSeparated(value)])]
  }),
})

const assigneeText = computed({
  get: () => props.modelValue.assignee.filter((id) => id !== props.userId && id !== 'unassigned').join('، '),
  set: (value: string) => patch((draft) => {
    const keepSpecial = draft.assignee.filter((id) => id === props.userId || id === 'unassigned')
    draft.assignee = [...new Set([...keepSpecial, ...commaSeparated(value)])]
  }),
})

function commaSeparated(value: string): string[] {
  return value.split(/[,،]/).map((item) => item.trim()).filter(Boolean)
}

function dateField(key: 'created_from' | 'created_to' | 'updated_from' | 'updated_to') {
  return computed({
    get: () => props.modelValue[key],
    set: (value: string) => patch((draft) => { draft[key] = value }),
  })
}

const createdFrom = dateField('created_from')
const createdTo = dateField('created_to')
const updatedFrom = dateField('updated_from')
const updatedTo = dateField('updated_to')
</script>

<template>
  <div class="bug-advanced-filters">
    <div class="bug-advanced-filters__shortcuts">
      <UButton v-if="userId" size="sm" :color="reportedByMe ? 'primary' : 'neutral'" :variant="reportedByMe ? 'subtle' : 'outline'" :disabled="pending" @click="toggleReportedByMe">گزارش‌شده توسط من</UButton>
      <UButton v-if="userId" size="sm" :color="assignedToMe ? 'primary' : 'neutral'" :variant="assignedToMe ? 'subtle' : 'outline'" :disabled="pending" @click="toggleAssignedToMe">تخصیص‌یافته به من</UButton>
      <UButton size="sm" :color="onlyUnassigned ? 'primary' : 'neutral'" :variant="onlyUnassigned ? 'subtle' : 'outline'" :disabled="pending" @click="toggleUnassigned">بدون مسئول</UButton>
    </div>

    <USeparator />

    <div class="bug-advanced-filters__grid">
      <ProjectTrackingValueField id="filter-priority" kind="priority" :values="trackingValues" :projects="projects" :model-value="modelValue.priority" :loading="trackingLoading" :error="trackingError" :disabled="trackingDisabled" @update:model-value="setTracking('priority', $event)" @retry="emit('retryOptions')" />
      <ProjectTrackingValueField id="filter-severity" kind="severity" :values="trackingValues" :projects="projects" :model-value="modelValue.severity" :loading="trackingLoading" :error="trackingError" :disabled="trackingDisabled" @update:model-value="setTracking('severity', $event)" @retry="emit('retryOptions')" />
      <ProjectTrackingValueField id="filter-category" kind="category" :values="trackingValues" :projects="projects" :model-value="modelValue.category" :loading="trackingLoading" :error="trackingError" :disabled="trackingDisabled" @update:model-value="setTracking('category', $event)" @retry="emit('retryOptions')" />
      <ProjectTrackingValueField id="filter-tag" kind="tag" :values="trackingValues" :projects="projects" :model-value="modelValue.tag" :loading="trackingLoading" :error="trackingError" :disabled="trackingDisabled" @update:model-value="setTracking('tag', $event)" @retry="emit('retryOptions')" />
    </div>

    <USeparator />

    <div class="bug-advanced-filters__grid">
      <UFormField label="ایجادشده از" class="w-full" :ui="{ container: 'w-full' }"><UInput v-model="createdFrom" type="datetime-local" :disabled="pending" class="w-full" /></UFormField>
      <UFormField label="ایجادشده تا" class="w-full" :ui="{ container: 'w-full' }"><UInput v-model="createdTo" type="datetime-local" :disabled="pending" class="w-full" /></UFormField>
      <UFormField label="به‌روزشده از" class="w-full" :ui="{ container: 'w-full' }"><UInput v-model="updatedFrom" type="datetime-local" :disabled="pending" class="w-full" /></UFormField>
      <UFormField label="به‌روزشده تا" class="w-full" :ui="{ container: 'w-full' }"><UInput v-model="updatedTo" type="datetime-local" :disabled="pending" class="w-full" /></UFormField>
    </div>

    <USeparator />

    <div class="bug-advanced-filters__grid">
      <UFormField label="UUID گزارش‌دهنده" description="جدا‌شده با ویرگول" class="w-full" :ui="{ container: 'w-full' }"><UInput v-model="reporterText" dir="ltr" autocomplete="off" placeholder="uuid, uuid" :disabled="pending" class="w-full" /></UFormField>
      <UFormField label="UUID مسئول" description="جدا‌شده با ویرگول؛ جدا از «بدون مسئول»" class="w-full" :ui="{ container: 'w-full' }"><UInput v-model="assigneeText" dir="ltr" autocomplete="off" placeholder="uuid, uuid" :disabled="pending" class="w-full" /></UFormField>
    </div>

    <p class="bug-advanced-filters__help">ابعاد مختلف فیلتر با هم ترکیب (AND) می‌شوند؛ چند مقدار درون یک بعد با یکدیگر (OR) مطابقت دارند.</p>
  </div>
</template>

<style scoped>
.bug-advanced-filters { display: grid; gap: 1rem; padding: .25rem; }
.bug-advanced-filters__shortcuts { display: flex; flex-wrap: wrap; gap: .5rem; }
.bug-advanced-filters__grid { display: grid; gap: .85rem; grid-template-columns: repeat(2, minmax(0, 1fr)); }
.bug-advanced-filters__help { color: var(--ui-text-dimmed); font-size: .74rem; line-height: 1.6; margin: 0; }
@media (max-width: 30rem) { .bug-advanced-filters__grid { grid-template-columns: 1fr; } }
</style>
