<script setup lang="ts">
defineProps<{ message?: string; tone?: 'error' | 'success' }>()
</script>

<template>
  <p v-if="message" class="admin-notice" :class="`admin-notice--${tone || 'error'}`" role="alert">{{ message }}</p>
</template>
