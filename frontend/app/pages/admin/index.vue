<script setup lang="ts">
import { adminErrorMessage, adminFieldErrors, useAdministration } from '~/composables/useAdministration'

definePageMeta({ middleware: 'auth' })

const admin = useAdministration()
const router = useRouter()
const project = reactive({ key: '', name: '', description: '' })
const projectKey = ref('')
const pending = ref(false)
const message = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

async function createProject() {
  if (pending.value) return
  pending.value = true; message.value = ''; fieldErrors.value = {}
  try {
    const response = await admin.createProject({ ...project, description: project.description || null })
    await router.push(`/admin/projects/${response.data.key}`)
  } catch (error) {
    message.value = adminErrorMessage(error); fieldErrors.value = adminFieldErrors(error)
  } finally { pending.value = false }
}

function openProject() {
  const key = projectKey.value.trim().toUpperCase()
  if (key) router.push(`/admin/projects/${key}`)
}
</script>

<template>
  <main class="admin-page">
    <header class="admin-header"><div><p class="eyebrow">Administration</p><h1>BugSense control room</h1><p>Projects, participants, and project configuration.</p></div><NuxtLink class="admin-link" to="/admin/users">Users</NuxtLink></header>
    <nav class="admin-tabs" aria-label="Administration"><NuxtLink to="/admin">Projects</NuxtLink><NuxtLink to="/admin/users">Users</NuxtLink></nav>
    <section class="admin-grid">
      <article class="admin-panel"><h2>Create project</h2><p class="panel-copy">System-wide administrators create projects. Laravel confirms scope and validation.</p><AdminNotice :message="message" /><form class="admin-form" @submit.prevent="createProject"><label>Key<input v-model.trim="project.key" :disabled="pending" maxlength="20" required placeholder="CORE"></label><p v-if="fieldErrors.key?.[0]" class="field-error">{{ fieldErrors.key[0] }}</p><label>Name<input v-model.trim="project.name" :disabled="pending" maxlength="150" required></label><p v-if="fieldErrors.name?.[0]" class="field-error">{{ fieldErrors.name[0] }}</p><label>Description<textarea v-model="project.description" :disabled="pending" rows="3" /></label><button class="button button-primary" :disabled="pending">{{ pending ? 'Creating…' : 'Create project' }}</button></form></article>
      <article class="admin-panel"><h2>Open project administration</h2><p class="panel-copy">Current API exposes project detail by key. Enter an existing project key.</p><form class="admin-form" @submit.prevent="openProject"><label>Project key<input v-model.trim="projectKey" maxlength="20" required placeholder="CORE"></label><button class="button button-secondary" type="submit">Open project</button></form></article>
    </section>
  </main>
</template>
