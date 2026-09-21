<script setup lang="ts">
defineProps<{ name: string; userId?: string; active?: boolean; compact?: boolean }>()
</script>

<template>
  <span :class="['user-identity', { 'user-identity--compact': compact }]">
    <span dir="auto">{{ name }}</span>
    <TechnicalValue v-if="userId && !compact" :value="userId" class="user-identity__id" />
    <span v-if="active === false" class="user-identity__inactive">حساب غیرفعال</span>
  </span>
</template>

<style scoped>
.user-identity { display: inline-grid; gap: .1rem; min-width: 0; }
.user-identity--compact { display: inline-flex; gap: .35rem; }
.user-identity__id, .user-identity__inactive { color: var(--ui-text-dimmed); font-size: .72rem; }
</style>
