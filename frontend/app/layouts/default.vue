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
      <WorkspaceSwitcher />
      <div class="app-account"><span>{{ session.user.value?.display_name || session.user.value?.email }}</span><button class="topbar-button" :disabled="signingOut" @click="signOut">{{ signingOut ? 'Signing out…' : 'Sign out' }}</button></div>
    </header>
    <div class="app-content"><slot /></div>
  </div>
</template>
