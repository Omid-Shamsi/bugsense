<script setup lang="ts">
import { fa_ir } from '@nuxt/ui/locale'

useHead({
  meta: [
    { charset: 'utf-8' },
    { name: 'viewport', content: 'width=device-width, initial-scale=1' },
    { name: 'theme-color', content: '#09090b' }
  ],
  htmlAttrs: {
    lang: 'fa',
    dir: 'rtl'
  }
})
</script>

<template>
  <UApp :locale="fa_ir">
    <NuxtLoadingIndicator color="#3b82f6" />
    <NuxtRouteAnnouncer />
    <NuxtLayout>
      <NuxtPage />
    </NuxtLayout>
  </UApp>
</template>
