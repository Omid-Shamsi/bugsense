<script setup lang="ts">
import { adminErrorMessage, useAdministration, type AdminMembership, type AdminProject, type AdminTrackingValue, type TrackingKind } from '~/composables/useAdministration'

definePageMeta({ middleware: 'auth' })
const route = useRoute()
const admin = useAdministration()
const project = ref<AdminProject | null>(null)
const memberships = ref<AdminMembership[]>([])
const trackingValues = ref<AdminTrackingValue[]>([])
const loading = ref(true)
const pending = ref('')
const message = ref('')
const projectForm = reactive({ name: '', description: '' })
const memberForm = reactive({ user_id: '', roles: ['reporter'] as string[] })
const valueForm = reactive({ kind: 'category' as TrackingKind, code: '', name: '', rank: '' as number | '' })
const roleSelections = reactive<Record<string, string[]>>({})
const key = computed(() => String(route.params.key).toUpperCase())
const roles = ['reporter', 'developer', 'qa', 'admin']
const kinds: TrackingKind[] = ['category', 'priority', 'severity', 'tag', 'resolution_label']

async function load() {
  loading.value = true; message.value = ''
  try {
    const [projectResponse, membershipResponse, valuesResponse] = await Promise.all([admin.getProject(key.value), admin.listMemberships(key.value), admin.listTrackingValues(key.value)])
    project.value = projectResponse.data
    projectForm.name = project.value.name; projectForm.description = project.value.description || ''
    memberships.value = membershipResponse.data
    memberships.value.forEach((membership) => { roleSelections[membership.id] = [...membership.roles] })
    trackingValues.value = valuesResponse.data
  } catch (error) { message.value = adminErrorMessage(error) } finally { loading.value = false }
}
async function saveProject() {
  if (!project.value) return
  pending.value = 'project'; message.value = ''
  try { project.value = (await admin.updateProject(key.value, { name: projectForm.name, description: projectForm.description || null })).data } catch (error) { message.value = adminErrorMessage(error) } finally { pending.value = '' }
}
async function deactivateProject() {
  if (!project.value) return
  pending.value = 'project-status'; message.value = ''
  try { project.value = (await admin.updateProject(key.value, { active: false })).data } catch (error) { message.value = adminErrorMessage(error) } finally { pending.value = '' }
}
async function createMembership() {
  pending.value = 'member-create'; message.value = ''
  try { await admin.createMembership(key.value, memberForm); Object.assign(memberForm, { user_id: '', roles: ['reporter'] }); await load() } catch (error) { message.value = adminErrorMessage(error) } finally { pending.value = '' }
}
async function saveMembership(membership: AdminMembership) {
  pending.value = membership.id; message.value = ''
  try { await admin.updateMembership(membership.id, { roles: roleSelections[membership.id] || [] }); await load() } catch (error) { message.value = adminErrorMessage(error) } finally { pending.value = '' }
}
async function deactivateMembership(membership: AdminMembership) {
  pending.value = membership.id; message.value = ''
  try { await admin.updateMembership(membership.id, { active: false }); await load() } catch (error) { message.value = adminErrorMessage(error) } finally { pending.value = '' }
}
async function createValue() {
  pending.value = 'value-create'; message.value = ''
  try { await admin.createTrackingValue(key.value, { ...valueForm, rank: valueForm.rank === '' ? null : Number(valueForm.rank) }); Object.assign(valueForm, { kind: 'category', code: '', name: '', rank: '' }); await load() } catch (error) { message.value = adminErrorMessage(error) } finally { pending.value = '' }
}
async function deactivateValue(value: AdminTrackingValue) {
  pending.value = value.id; message.value = ''
  try { await admin.updateTrackingValue(value.id, { active: false }); await load() } catch (error) { message.value = adminErrorMessage(error) } finally { pending.value = '' }
}

await load()
</script>

<template>
  <main class="admin-page"><header class="admin-header"><div><p class="eyebrow">Project administration</p><h1>{{ project?.name || key }}</h1><p>{{ project ? `${project.key} · ${project.description || 'No description'}` : 'Loading project…' }}</p></div><NuxtLink class="admin-link" to="/admin">All admin</NuxtLink></header><nav class="admin-tabs" aria-label="Administration"><NuxtLink to="/admin">Projects</NuxtLink><NuxtLink to="/admin/users">Users</NuxtLink></nav><AdminNotice :message="message" />
    <p v-if="loading" class="panel-copy">Loading project administration…</p><template v-else-if="project"><section class="admin-grid"><article class="admin-panel"><div class="panel-title"><h2>Project settings</h2><AdminStatusBadge :active="project.active" /></div><form class="admin-form" @submit.prevent="saveProject"><label>Name<input v-model.trim="projectForm.name" required :disabled="!!pending"></label><label>Description<textarea v-model="projectForm.description" rows="3" :disabled="!!pending" /></label><button class="button button-primary" :disabled="!!pending">{{ pending === 'project' ? 'Saving…' : 'Save settings' }}</button><button v-if="project.active" class="button button-secondary" type="button" :disabled="!!pending" @click="deactivateProject">{{ pending === 'project-status' ? 'Saving…' : 'Deactivate project' }}</button><p v-else class="panel-copy">This project is retained as an inactive record.</p></form></article>
      <article class="admin-panel"><h2>Add participant</h2><p class="panel-copy">Use existing user UUID. Server checks role and project scope.</p><form class="admin-form" @submit.prevent="createMembership"><label>User ID<input v-model.trim="memberForm.user_id" required :disabled="!!pending" placeholder="UUID"></label><fieldset><legend>Roles</legend><label v-for="role in roles" :key="role" class="check-row"><input v-model="memberForm.roles" :value="role" type="checkbox" :disabled="!!pending">{{ role }}</label></fieldset><button class="button button-primary" :disabled="!!pending || !memberForm.roles.length">{{ pending === 'member-create' ? 'Adding…' : 'Add participant' }}</button></form></article></section>
      <section class="admin-panel"><h2>Participants and role grants</h2><p v-if="!memberships.length" class="panel-copy">No participants yet.</p><div v-else class="admin-table-wrap"><table class="admin-table"><thead><tr><th>User ID</th><th>Roles</th><th>Status</th><th>Actions</th></tr></thead><tbody><tr v-for="membership in memberships" :key="membership.id"><td class="mono">{{ membership.user_id }}</td><td><div class="role-list"><label v-for="role in roles" :key="role" class="check-row"><input v-model="roleSelections[membership.id]" :value="role" type="checkbox" :disabled="!!pending">{{ role }}</label></div></td><td><AdminStatusBadge :active="membership.active" /></td><td class="action-stack"><button class="text-button" :disabled="!!pending" @click="saveMembership(membership)">{{ pending === membership.id ? 'Saving…' : 'Save roles' }}</button><button v-if="membership.active" class="text-button" :disabled="!!pending" @click="deactivateMembership(membership)">{{ pending === membership.id ? 'Saving…' : 'Deactivate' }}</button><span v-else class="muted">Retained inactive record</span></td></tr></tbody></table></div></section>
      <section class="admin-grid"><article class="admin-panel"><h2>Add tracking value</h2><form class="admin-form" @submit.prevent="createValue"><label>Kind<select v-model="valueForm.kind" :disabled="!!pending"><option v-for="kind in kinds" :key="kind" :value="kind">{{ kind.replace('_', ' ') }}</option></select></label><label>Code<input v-model.trim="valueForm.code" required maxlength="60" :disabled="!!pending"></label><label>Name<input v-model.trim="valueForm.name" required :disabled="!!pending"></label><label>Rank <span class="muted">optional</span><input v-model="valueForm.rank" type="number" min="0" :disabled="!!pending"></label><button class="button button-primary" :disabled="!!pending">{{ pending === 'value-create' ? 'Adding…' : 'Add value' }}</button></form></article>
        <article class="admin-panel admin-panel--wide"><h2>Tracking values</h2><p v-if="!trackingValues.length" class="panel-copy">No tracking values yet.</p><div v-else class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Kind</th><th>Value</th><th>Rank</th><th>Status</th><th>Action</th></tr></thead><tbody><tr v-for="value in trackingValues" :key="value.id"><td>{{ value.kind.replace('_', ' ') }}</td><td><strong>{{ value.name }}</strong><small>{{ value.code }}</small></td><td>{{ value.rank ?? '—' }}</td><td><AdminStatusBadge :active="value.active" /></td><td><button v-if="value.active" class="text-button" :disabled="!!pending" @click="deactivateValue(value)">{{ pending === value.id ? 'Saving…' : 'Deactivate' }}</button><span v-else class="muted">Retained inactive record</span></td></tr></tbody></table></div></article></section>
    </template>
  </main>
</template>
