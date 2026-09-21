<script setup lang="ts">
defineProps<{ publicId: string; title: string; to: string; id?: string }>()
</script>

<template>
  <NuxtLink :id="id" class="bug-identity-link" :to="to">
    <span dir="auto">{{ title }}</span>
    <TechnicalValue :value="publicId" />
  </NuxtLink>
</template>

<style scoped>
.bug-identity-link { color: var(--ui-text-highlighted); display: grid; font-weight: 650; gap: .2rem; line-height: 1.5; min-width: 0; text-decoration: none; }
.bug-identity-link > span { display: -webkit-box; overflow: hidden; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.bug-identity-link:hover > span { text-decoration: underline; text-underline-offset: .2rem; }
.bug-identity-link :deep(bdi) { color: var(--ui-text-muted); font-size: .74rem; font-weight: 400; unicode-bidi: isolate; }
</style>
