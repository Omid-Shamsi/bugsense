<script setup lang="ts">
import { adminErrorMessage, adminFieldErrors, useAdministration, type AdminUser } from '~/composables/useAdministration'

definePageMeta({ middleware: 'auth' })
const admin = useAdministration()
const users = ref<AdminUser[]>([])
const query = ref('')
const loading = ref(true)
const pendingId = ref<string | null>(null)
const message = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
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
  pendingId.value = user.id; message.value = ''
  try { await admin.updateUser(user.id, { active: false }); await load() } catch (error) { message.value = adminErrorMessage(error) } finally { pendingId.value = null }
}
await load()
</script>

<template>
  <main class="admin-page"><header class="admin-header"><div><p class="eyebrow">Administration</p><h1>Users</h1><p>System directory. User records remain retained when inactive.</p></div><NuxtLink class="admin-link" to="/admin">Projects</NuxtLink></header><nav class="admin-tabs" aria-label="Administration"><NuxtLink to="/admin">Projects</NuxtLink><NuxtLink to="/admin/users">Users</NuxtLink></nav><AdminNotice :message="message" />
    <section class="admin-grid"><article class="admin-panel"><h2>Create user</h2><form class="admin-form" @submit.prevent="create"><label>Email<input v-model.trim="form.email" type="email" required :disabled="pendingId === 'create'"></label><p v-if="fieldErrors.email?.[0]" class="field-error">{{ fieldErrors.email[0] }}</p><label>Display name<input v-model.trim="form.display_name" required :disabled="pendingId === 'create'"></label><p v-if="fieldErrors.display_name?.[0]" class="field-error">{{ fieldErrors.display_name[0] }}</p><label>Password<input v-model="form.password" type="password" minlength="12" required :disabled="pendingId === 'create'"></label><p v-if="fieldErrors.password?.[0]" class="field-error">{{ fieldErrors.password[0] }}</p><label class="check-row"><input v-model="form.is_system_admin" type="checkbox" :disabled="pendingId === 'create'">System-wide Admin</label><button class="button button-primary" :disabled="pendingId === 'create'">{{ pendingId === 'create' ? 'Creating…' : 'Create user' }}</button></form></article>
      <article class="admin-panel admin-panel--wide"><div class="panel-title"><h2>User directory</h2><form class="search-form" @submit.prevent="load"><input v-model.trim="query" type="search" placeholder="Search name or email"><button class="button button-secondary">Search</button></form></div><p v-if="loading" class="panel-copy">Loading users…</p><p v-else-if="!users.length" class="panel-copy">No users found.</p><div v-else class="admin-table-wrap"><table class="admin-table"><thead><tr><th>User</th><th>Scope</th><th>Status</th><th>Action</th></tr></thead><tbody><tr v-for="user in users" :key="user.id"><td><strong>{{ user.display_name }}</strong><small>{{ user.email }}</small></td><td>{{ user.is_system_admin ? 'System Admin' : 'Standard user' }}</td><td><AdminStatusBadge :active="user.active" /></td><td><button v-if="user.active" class="text-button" :disabled="pendingId === user.id" @click="deactivate(user)">{{ pendingId === user.id ? 'Saving…' : 'Deactivate' }}</button><span v-else class="muted">Retained inactive record</span></td></tr></tbody></table></div></article></section>
  </main>
</template>
