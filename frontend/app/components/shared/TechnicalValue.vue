<script setup lang="ts">
defineProps<{ value: string; title?: string; class?: string }>()
</script>

<template>
  <bdi :class="$props.class" dir="ltr" :title="title || value"><slot>{{ value }}</slot></bdi>
</template>
