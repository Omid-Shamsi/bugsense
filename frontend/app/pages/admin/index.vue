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
    <header class="admin-header"><div><p class="eyebrow">مدیریت</p><h1>مرکز مدیریت BugSense</h1><p>پروژه‌ها، اعضا و پیکربندی پروژه.</p></div><NuxtLink class="admin-link" to="/admin/users">کاربران</NuxtLink></header>
    <nav class="admin-tabs" aria-label="مدیریت"><NuxtLink to="/admin">پروژه‌ها</NuxtLink><NuxtLink to="/admin/users">کاربران</NuxtLink></nav>
    <section class="admin-grid">
      <article class="admin-panel"><h2>ایجاد پروژه</h2><p class="panel-copy">مدیران کل سیستم پروژه ایجاد می‌کنند. دامنه و اعتبارسنجی در سرور تأیید می‌شود.</p><AdminNotice :message="message" /><form class="admin-form" @submit.prevent="createProject"><label>کلید پروژه<input v-model.trim="project.key" dir="ltr" :disabled="pending" maxlength="20" required placeholder="CORE"></label><p v-if="fieldErrors.key?.[0]" class="field-error">{{ fieldErrors.key[0] }}</p><label>نام<input v-model.trim="project.name" :disabled="pending" maxlength="150" required></label><p v-if="fieldErrors.name?.[0]" class="field-error">{{ fieldErrors.name[0] }}</p><label>توضیحات<textarea v-model="project.description" :disabled="pending" rows="3" /></label><button class="button button-primary" :disabled="pending">{{ pending ? 'در حال ایجاد…' : 'ایجاد پروژه' }}</button></form></article>
      <article class="admin-panel"><h2>مدیریت پروژه</h2><p class="panel-copy">کلید یک پروژه موجود را وارد کنید.</p><form class="admin-form" @submit.prevent="openProject"><label>کلید پروژه<input v-model.trim="projectKey" dir="ltr" maxlength="20" required placeholder="CORE"></label><button class="button button-secondary" type="submit">باز کردن پروژه</button></form></article>
    </section>
  </main>
</template>
