<script setup lang="ts">
import { roleLabel } from '~/utils/presentation'

const props = withDefaults(defineProps<{ roles?: readonly string[]; systemAdmin?: boolean; projectAdmin?: boolean }>(), { roles: () => [], systemAdmin: false, projectAdmin: false })
const orderedRoles = computed(() => ['reporter', 'developer', 'qa', 'admin'].filter(role => props.roles.includes(role)))
</script>

<template>
  <span class="role-badges">
    <UBadge v-if="systemAdmin" color="primary" variant="outline">مدیر سراسری</UBadge>
    <UBadge v-if="projectAdmin" color="neutral" variant="outline">مدیر پروژه</UBadge>
    <UBadge v-for="role in orderedRoles.filter(role => role !== 'admin')" :key="role" color="neutral" variant="subtle">{{ roleLabel(role) }}</UBadge>
    <span v-if="!systemAdmin && !projectAdmin && !orderedRoles.length" class="role-badges__empty">بدون نقش عملیاتی</span>
  </span>
</template>

<style scoped>
.role-badges{align-items:center;display:inline-flex;flex-wrap:wrap;gap:.3rem}.role-badges__empty{color:var(--ui-text-muted);font-size:.76rem}
</style>
