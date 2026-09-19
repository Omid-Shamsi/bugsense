<script setup lang="ts">
import { roleLabel } from '~/utils/presentation'

const workspace = useWorkspace()

function change(event: Event) {
  workspace.selectProject((event.target as HTMLSelectElement).value)
}
</script>

<template>
  <div class="workspace-switcher">
    <label class="sr-only" for="workspace-project">پروژه قابل مشاهده</label>
    <select id="workspace-project" :value="workspace.selectedKey.value || ''" :disabled="workspace.loading.value || workspace.projects.value.length < 2" @change="change">
      <option v-if="!workspace.projects.value.length" value="">پروژه‌ای قابل مشاهده نیست</option>
      <option v-for="project in workspace.projects.value" :key="project.id" :value="project.key">{{ project.name }} · {{ project.key }}</option>
    </select>
    <span v-if="workspace.selectedRoles.value.length" class="workspace-roles">{{ workspace.selectedRoles.value.map(roleLabel).join(' · ') }}</span>
  </div>
</template>
