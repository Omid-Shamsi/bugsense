<script setup lang="ts">
import type { BugTrackingValue } from '~/composables/useBugs'
import type { VisibleProject } from '~/composables/useWorkspace'
import { trackingKindLabel } from '~/utils/presentation'

const props = withDefaults(defineProps<{
  id: string
  kind: BugTrackingValue['kind']
  label?: string
  values: readonly BugTrackingValue[]
  projects: readonly VisibleProject[]
  modelValue: readonly string[]
  loading?: boolean
  error?: string
  disabled?: boolean
  placeholder?: string
}>(), {
  label: '',
  loading: false,
  error: '',
  disabled: false,
  placeholder: '',
})

const emit = defineEmits<{
  'update:modelValue': [ids: string[]]
  retry: []
}>()

const labelId = computed(() => `${props.id}-label`)
const fieldLabel = computed(() => props.label || trackingKindLabel(props.kind))
const projectByKey = computed(() => new Map(props.projects.map((project) => [project.id, project])))

const options = computed(() => props.values
  .filter((value) => value.kind === props.kind)
  .map((value) => ({
    id: value.id,
    label: optionLabel(value),
    active: value.active,
  }))
  .sort((left, right) => {
    if (left.active !== right.active) return left.active ? -1 : 1
    return left.label.localeCompare(right.label, 'fa')
  }))

const selectedItems = computed(() => options.value.filter((option) => props.modelValue.includes(option.id)))
const shownItems = computed(() => selectedItems.value.slice(0, 3))
const hiddenCount = computed(() => Math.max(0, selectedItems.value.length - shownItems.value.length))

function optionLabel(value: BugTrackingValue): string {
  const project = projectByKey.value.get(value.project_id)
  const prefix = project?.key ? `${project.key} · ` : ''
  return `${prefix}${value.name}${value.active ? '' : ' (غیرفعال)'}`
}

function onUpdate(next: string[]) {
  emit('update:modelValue', next)
}
</script>

<template>
  <UFormField :name="id" class="w-full" :ui="{ container: 'w-full' }">
    <template #label>
      <span :id="labelId">{{ fieldLabel }}</span>
    </template>

    <USkeleton v-if="loading" class="h-10 w-full" />
    <template v-else>
      <USelectMenu
        :id="id"
        :model-value="[...modelValue]"
        :aria-labelledby="labelId"
        multiple
        :items="options"
        value-key="id"
        label-key="label"
        :disabled="disabled"
        :placeholder="placeholder || `همهٔ ${fieldLabel}`"
        :search-input="options.length > 8"
        class="w-full"
        @update:model-value="onUpdate"
      >
        <template #default>
          <span v-if="shownItems.length" class="flex min-w-0 flex-wrap items-center gap-1">
            <UBadge v-for="item in shownItems" :key="item.id" color="neutral" variant="subtle" size="xs">{{ item.label }}</UBadge>
            <UBadge v-if="hiddenCount" color="neutral" variant="subtle" size="xs">+{{ hiddenCount }}</UBadge>
          </span>
          <span v-else class="text-muted">{{ placeholder || `همهٔ ${fieldLabel}` }}</span>
        </template>
      </USelectMenu>

      <div v-if="error" class="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-error" role="alert">
        <span>{{ error }}</span>
        <UButton color="error" variant="ghost" size="xs" type="button" @click="emit('retry')">تلاش مجدد</UButton>
      </div>
      <p v-else-if="!loading && !options.length" class="mt-1.5 text-xs text-muted">مقدار فعالی برای این بعد تعریف نشده است.</p>
    </template>
  </UFormField>
</template>
