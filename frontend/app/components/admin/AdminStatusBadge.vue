<script setup lang="ts">
defineProps<{ active: boolean }>()
</script>

<template>
  <span class="status-badge" :class="active ? 'status-badge--active' : 'status-badge--inactive'">{{ active ? 'Active' : 'Inactive' }}</span>
</template>
