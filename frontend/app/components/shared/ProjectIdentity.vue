<script setup lang="ts">
defineProps<{ name: string; projectKey: string }>()
</script>

<template>
  <span class="project-identity"><span>{{ name }}</span><TechnicalValue :value="projectKey" class="project-identity__key" /></span>
</template>

<style scoped>
.project-identity { align-items: center; display: inline-flex; flex-wrap: wrap; gap: .45rem; min-width: 0; }
.project-identity__key { color: var(--ui-text-dimmed); font-size: .78rem; unicode-bidi: isolate; }
</style>
