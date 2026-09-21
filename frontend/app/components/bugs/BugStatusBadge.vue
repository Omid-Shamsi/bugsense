<script setup lang="ts">
import { statusLabel } from '~/utils/presentation'
const props = defineProps<{ status: string; compact?: boolean }>()
</script>

<template>
  <UBadge :class="['bug-status', `bug-status--${props.status}`, { 'bug-status--compact': compact }]" variant="subtle">
    {{ statusLabel(status) }}
  </UBadge>
</template>
