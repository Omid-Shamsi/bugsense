<script setup lang="ts">
const workspace = useWorkspace()

function change(event: Event) {
  workspace.selectProject((event.target as HTMLSelectElement).value)
}
</script>

<template>
  <div class="workspace-switcher">
    <label class="sr-only" for="workspace-project">Visible project</label>
    <select id="workspace-project" :value="workspace.selectedKey.value || ''" :disabled="workspace.loading.value || workspace.projects.value.length < 2" @change="change">
      <option v-if="!workspace.projects.value.length" value="">No visible projects</option>
      <option v-for="project in workspace.projects.value" :key="project.id" :value="project.key">{{ project.name }} · {{ project.key }}</option>
    </select>
    <span v-if="workspace.selectedRoles.value.length" class="workspace-roles">{{ workspace.selectedRoles.value.join(' · ') }}</span>
  </div>
</template>
