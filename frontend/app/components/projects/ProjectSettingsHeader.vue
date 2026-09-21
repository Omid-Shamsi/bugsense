<script setup lang="ts">
import type { AdminProject } from '~/composables/useAdministration'

defineProps<{ project: AdminProject; canManage: boolean }>()
</script>

<template>
  <section class="project-settings-header">
    <div class="project-settings-header__identity"><NuxtLink to="/projects" class="project-settings-header__back">پروژه‌ها</NuxtLink><div><h1 dir="auto">{{ project.name }}</h1><TechnicalValue :value="project.key" class="project-settings-header__key" /></div></div>
    <UBadge :color="project.active ? 'success' : 'warning'" variant="subtle">{{ project.active ? 'فعال' : 'غیرفعال' }}</UBadge>
  </section>
  <nav class="project-settings-nav" aria-label="بخش‌های پروژه"><NuxtLink :to="`/projects/${encodeURIComponent(project.key)}`" exact-active-class="project-settings-nav__active">عمومی</NuxtLink><NuxtLink v-if="canManage" :to="`/projects/${encodeURIComponent(project.key)}/members`" active-class="project-settings-nav__active">اعضا و نقش‌ها</NuxtLink><NuxtLink :to="`/projects/${encodeURIComponent(project.key)}/tracking`" active-class="project-settings-nav__active">مقادیر پیگیری</NuxtLink></nav>
</template>

<style scoped>
.project-settings-header{align-items:flex-start;border-bottom:1px solid var(--ui-border);display:flex;gap:1rem;justify-content:space-between;padding:1.25rem 1.5rem .9rem}.project-settings-header__identity{align-items:flex-start;display:flex;gap:.8rem;min-width:0}.project-settings-header__back{color:var(--ui-text-muted);font-size:.82rem;text-decoration:none}.project-settings-header__back:hover{text-decoration:underline;text-underline-offset:.2rem}.project-settings-header h1{color:var(--ui-text-highlighted);font-size:1.15rem;margin:0}.project-settings-header__key{color:var(--ui-text-muted);display:block;font-size:.78rem;margin-top:.25rem}.project-settings-nav{border-bottom:1px solid var(--ui-border);display:flex;gap:.35rem;overflow:auto;padding:.55rem 1.5rem}.project-settings-nav a{border-radius:4px;color:var(--ui-text-muted);font-size:.84rem;padding:.45rem .65rem;text-decoration:none;white-space:nowrap}.project-settings-nav a:hover,.project-settings-nav__active{background:var(--ui-bg-elevated);color:var(--ui-text-highlighted)}@media(max-width:767px){.project-settings-header{padding:1rem}.project-settings-nav{padding-inline:1rem}}
</style>
