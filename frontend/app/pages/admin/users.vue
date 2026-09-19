<script setup lang="ts">
import { adminErrorMessage, adminFieldErrors, useAdministration, type AdminUser } from '~/composables/useAdministration'
import { ApiError } from '~/plugins/api.client'

definePageMeta({ middleware: 'auth' })
const admin = useAdministration()
const users = ref<AdminUser[]>([])
const query = ref('')
const loading = ref(true)
const pendingId = ref<string | null>(null)
const message = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const remediationTarget = ref<AdminUser | null>(null)
const remediationMessage = ref('')
const form = reactive({ email: '', display_name: '', password: '', is_system_admin: false })

async function load() {
  loading.value = true; message.value = ''
  try { users.value = (await admin.listUsers(query.value)).data } catch (error) { message.value = adminErrorMessage(error) } finally { loading.value = false }
}
async function create() {
  pendingId.value = 'create'; message.value = ''; fieldErrors.value = {}
  try { await admin.createUser(form); Object.assign(form, { email: '', display_name: '', password: '', is_system_admin: false }); await load() } catch (error) { message.value = adminErrorMessage(error); fieldErrors.value = adminFieldErrors(error) } finally { pendingId.value = null }
}
async function deactivate(user: AdminUser) {
  pendingId.value = user.id; message.value = ''; remediationTarget.value = null
  try { await admin.updateUser(user.id, { active: false }); await load() } catch (error) {
    message.value = adminErrorMessage(error)
    if (error instanceof ApiError && error.status === 409) { remediationTarget.value = user; remediationMessage.value = message.value }
  } finally { pendingId.value = null }
}
async function remediate(remediation: { unassign: true } | { reassign_to: string }) {
  if (!remediationTarget.value || pendingId.value) return
  const target = remediationTarget.value
  pendingId.value = target.id; message.value = ''
  try { await admin.updateUser(target.id, { active: false, remediation }); remediationTarget.value = null; await load() } catch (error) { message.value = adminErrorMessage(error); remediationMessage.value = message.value } finally { pendingId.value = null }
}
await load()
</script>

<template>
  <main class="admin-page"><header class="admin-header"><div><p class="eyebrow">مدیریت</p><h1>کاربران</h1><p>فهرست سیستم. رکورد کاربران پس از غیرفعال شدن نیز نگهداری می‌شود.</p></div><NuxtLink class="admin-link" to="/admin">پروژه‌ها</NuxtLink></header><nav class="admin-tabs" aria-label="مدیریت"><NuxtLink to="/admin">پروژه‌ها</NuxtLink><NuxtLink to="/admin/users">کاربران</NuxtLink></nav><AdminNotice :message="message" />
    <section class="admin-grid"><article class="admin-panel"><h2>ایجاد کاربر</h2><form class="admin-form" @submit.prevent="create"><label>ایمیل<input v-model.trim="form.email" dir="ltr" type="email" required :disabled="pendingId === 'create'"></label><p v-if="fieldErrors.email?.[0]" class="field-error">{{ fieldErrors.email[0] }}</p><label>نام نمایشی<input v-model.trim="form.display_name" required :disabled="pendingId === 'create'"></label><p v-if="fieldErrors.display_name?.[0]" class="field-error">{{ fieldErrors.display_name[0] }}</p><label>رمز عبور<input v-model="form.password" type="password" minlength="12" required :disabled="pendingId === 'create'"></label><p v-if="fieldErrors.password?.[0]" class="field-error">{{ fieldErrors.password[0] }}</p><label class="check-row"><input v-model="form.is_system_admin" type="checkbox" :disabled="pendingId === 'create'">مدیر کل سیستم</label><button class="button button-primary" :disabled="pendingId === 'create'">{{ pendingId === 'create' ? 'در حال ایجاد…' : 'ایجاد کاربر' }}</button></form></article>
      <article class="admin-panel admin-panel--wide"><div class="panel-title"><h2>فهرست کاربران</h2><form class="search-form" @submit.prevent="load"><input v-model.trim="query" type="search" placeholder="جستجوی نام یا ایمیل"><button class="button button-secondary">جستجو</button></form></div><AdminRemediationPanel v-if="remediationTarget" kind="assignment" :message="remediationMessage" :pending="pendingId === remediationTarget.id" @cancel="remediationTarget = null" @unassign="remediate({ unassign: true })" @reassign="remediate({ reassign_to: $event })" /><p v-if="loading" class="panel-copy">در حال بارگیری کاربران…</p><p v-else-if="!users.length" class="panel-copy">کاربری پیدا نشد.</p><div v-else class="admin-table-wrap"><table class="admin-table"><thead><tr><th>کاربر</th><th>دامنه</th><th>وضعیت</th><th>اقدام</th></tr></thead><tbody><tr v-for="user in users" :key="user.id"><td><strong>{{ user.display_name }}</strong><small dir="ltr">{{ user.email }}</small></td><td>{{ user.is_system_admin ? 'مدیر کل سیستم' : 'کاربر عادی' }}</td><td><AdminStatusBadge :active="user.active" /></td><td><button v-if="user.active" class="text-button" :disabled="pendingId === user.id" @click="deactivate(user)">{{ pendingId === user.id ? 'در حال ذخیره…' : 'غیرفعال کردن' }}</button><span v-else class="muted">رکورد غیرفعال نگهداری شده</span></td></tr></tbody></table></div></article></section>
  </main>
</template>
