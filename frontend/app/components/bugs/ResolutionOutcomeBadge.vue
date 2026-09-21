<script setup lang="ts">
import { resolutionLabel } from '~/utils/presentation'
defineProps<{ outcome: string }>()
</script>

<template>
  <UBadge color="neutral" variant="outline" class="resolution-outcome">{{ resolutionLabel(outcome) }}</UBadge>
</template>
