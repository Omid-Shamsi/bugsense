<script setup lang="ts">
withDefaults(defineProps<{
  state: 'loading' | 'ready' | 'empty' | 'error'
  label?: string
  busy?: boolean
}>(), { label: '', busy: false })
</script>

<template>
  <div :aria-busy="state === 'loading' || busy" :aria-label="label || undefined">
    <slot v-if="state === 'loading'" name="loading" />
    <slot v-else-if="state === 'error'" name="error" />
    <slot v-else-if="state === 'empty'" name="empty" />
    <slot v-else />
  </div>
</template>
