<script setup lang="ts">
defineProps<{ active: boolean }>()
</script>

<template>
  <span class="status-badge" :class="active ? 'status-badge--active' : 'status-badge--inactive'">{{ active ? 'فعال' : 'غیرفعال' }}</span>
</template>
