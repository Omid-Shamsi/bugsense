<script setup lang="ts">
const session = useSession()
const workspace = useWorkspace()
const signingOut = ref(false)

async function signOut() {
  if (signingOut.value) return
  signingOut.value = true
  try {
    await session.logout()
    workspace.clear()
    await navigateTo('/login')
  } finally {
    signingOut.value = false
  }
}
</script>

<template>
  <div class="app-shell">
    <header class="app-topbar">
      <NuxtLink class="app-brand" to="/admin"><span class="brand-mark" aria-hidden="true">B</span><span>BugSense</span></NuxtLink>
      <nav class="topbar-nav" aria-label="ناوبری اصلی">
        <NuxtLink class="topbar-link" to="/bugs">باگ‌ها</NuxtLink>
        <NuxtLink class="topbar-link" to="/dashboard">داشبورد</NuxtLink>
        <NuxtLink class="topbar-link" to="/bugs/new">ثبت باگ</NuxtLink>
        <NuxtLink class="topbar-link" to="/work/developer">کارهای توسعه‌دهنده</NuxtLink>
        <NuxtLink class="topbar-link" to="/work/qa">بررسی QA</NuxtLink>
        <NuxtLink class="topbar-link" to="/admin">مدیریت</NuxtLink>
      </nav>
      <WorkspaceSwitcher />
      <div class="app-account"><span :dir="session.user.value?.display_name ? undefined : 'ltr'">{{ session.user.value?.display_name || session.user.value?.email }}</span><button class="topbar-button" :disabled="signingOut" @click="signOut">{{ signingOut ? 'در حال خروج…' : 'خروج' }}</button></div>
    </header>
    <div class="app-content"><slot /></div>
  </div>
</template>
