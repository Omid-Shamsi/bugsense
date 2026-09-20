<script setup lang="ts">
import { roleLabel } from '~/utils/presentation'

defineProps<{
  compact?: boolean
}>()

const workspace = useWorkspace()

const projectItems = computed(() => workspace.projects.value.map(project => ({
  label: `${project.name} · ${project.key}`,
  value: project.key
})))

function change(value: unknown) {
  if (typeof value === 'string') workspace.selectProject(value)
}
</script>

<template>
  <div class="workspace-switcher">
    <label class="workspace-label" for="workspace-project">فضای کاری</label>
    <USelect
      id="workspace-project"
      :model-value="workspace.selectedKey.value || undefined"
      :items="projectItems"
      value-key="value"
      :placeholder="workspace.loading.value ? 'در حال بارگیری…' : 'پروژه‌ای قابل مشاهده نیست'"
      :disabled="workspace.loading.value || workspace.projects.value.length < 2"
      icon="i-lucide-folder-kanban"
      class="w-full"
      @update:model-value="change"
    />
    <span v-if="workspace.selectedRoles.value.length && !compact" class="workspace-roles">
      {{ workspace.selectedRoles.value.map(roleLabel).join(' · ') }}
    </span>
  </div>
</template>
