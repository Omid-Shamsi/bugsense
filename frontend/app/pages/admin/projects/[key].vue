<script setup lang="ts">
import { adminErrorMessage, useAdministration, type AdminMembership, type AdminProject, type AdminTrackingValue, type TrackingKind } from '~/composables/useAdministration'
import { ApiError } from '~/plugins/api.client'
import { roleLabel, trackingKindLabel } from '~/utils/presentation'

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
const membershipRemediation = ref<{ membership: AdminMembership; data: { active?: boolean; roles?: string[] }; message: string } | null>(null)
const valueRemediation = ref<{ value: AdminTrackingValue; message: string } | null>(null)
const key = computed(() => String(route.params.key).toUpperCase())
const roles = ['reporter', 'developer', 'qa', 'admin']
const kinds: TrackingKind[] = ['category', 'priority', 'severity', 'tag', 'resolution_label']
const replacementValues = computed(() => valueRemediation.value ? trackingValues.value.filter((value) => value.active && value.kind === valueRemediation.value?.value.kind && value.id !== valueRemediation.value.value.id) : [])

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
  const data = { roles: roleSelections[membership.id] || [] }
  pending.value = membership.id; message.value = ''; membershipRemediation.value = null
  try { await admin.updateMembership(membership.id, data); await load() } catch (error) {
    message.value = adminErrorMessage(error)
    if (error instanceof ApiError && error.status === 409) membershipRemediation.value = { membership, data, message: message.value }
  } finally { pending.value = '' }
}
async function deactivateMembership(membership: AdminMembership) {
  const data = { active: false }
  pending.value = membership.id; message.value = ''; membershipRemediation.value = null
  try { await admin.updateMembership(membership.id, data); await load() } catch (error) {
    message.value = adminErrorMessage(error)
    if (error instanceof ApiError && error.status === 409) membershipRemediation.value = { membership, data, message: message.value }
  } finally { pending.value = '' }
}
async function remediateMembership(remediation: { unassign: true } | { reassign_to: string }) {
  if (!membershipRemediation.value || pending.value) return
  const target = membershipRemediation.value
  pending.value = target.membership.id; message.value = ''
  try { await admin.updateMembership(target.membership.id, { ...target.data, remediation }); membershipRemediation.value = null; await load() } catch (error) { message.value = adminErrorMessage(error); target.message = message.value } finally { pending.value = '' }
}
function cancelMembershipRemediation() {
  if (membershipRemediation.value) roleSelections[membershipRemediation.value.membership.id] = [...membershipRemediation.value.membership.roles]
  membershipRemediation.value = null
}
async function createValue() {
  pending.value = 'value-create'; message.value = ''
  try { await admin.createTrackingValue(key.value, { ...valueForm, rank: valueForm.rank === '' ? null : Number(valueForm.rank) }); Object.assign(valueForm, { kind: 'category', code: '', name: '', rank: '' }); await load() } catch (error) { message.value = adminErrorMessage(error) } finally { pending.value = '' }
}
async function deactivateValue(value: AdminTrackingValue) {
  pending.value = value.id; message.value = ''; valueRemediation.value = null
  try { await admin.updateTrackingValue(value.id, { active: false }); await load() } catch (error) {
    message.value = adminErrorMessage(error)
    if (error instanceof ApiError && error.status === 409) valueRemediation.value = { value, message: message.value }
  } finally { pending.value = '' }
}
async function remediateValue(remediation: { unset: true } | { replace_with: string }) {
  if (!valueRemediation.value || pending.value) return
  const target = valueRemediation.value
  pending.value = target.value.id; message.value = ''
  try { await admin.updateTrackingValue(target.value.id, { active: false, remediation }); valueRemediation.value = null; await load() } catch (error) { message.value = adminErrorMessage(error); target.message = message.value } finally { pending.value = '' }
}

await load()
</script>

<template>
  <main class="admin-page"><header class="admin-header"><div><p class="eyebrow">مدیریت پروژه</p><h1>{{ project?.name || key }}</h1><p><template v-if="project"><bdi dir="ltr">{{ project.key }}</bdi> · {{ project.description || 'بدون توضیحات' }}</template><template v-else>در حال بارگیری پروژه…</template></p></div><NuxtLink class="admin-link" to="/admin">همه بخش‌های مدیریت</NuxtLink></header><nav class="admin-tabs" aria-label="مدیریت"><NuxtLink to="/admin">پروژه‌ها</NuxtLink><NuxtLink to="/admin/users">کاربران</NuxtLink></nav><AdminNotice :message="message" />
    <p v-if="loading" class="panel-copy">در حال بارگیری مدیریت پروژه…</p><template v-else-if="project"><section class="admin-grid"><article class="admin-panel"><div class="panel-title"><h2>تنظیمات پروژه</h2><AdminStatusBadge :active="project.active" /></div><form class="admin-form" @submit.prevent="saveProject"><label>نام<input v-model.trim="projectForm.name" required :disabled="!!pending"></label><label>توضیحات<textarea v-model="projectForm.description" rows="3" :disabled="!!pending" /></label><button class="button button-primary" :disabled="!!pending">{{ pending === 'project' ? 'در حال ذخیره…' : 'ذخیره تنظیمات' }}</button><button v-if="project.active" class="button button-secondary" type="button" :disabled="!!pending" @click="deactivateProject">{{ pending === 'project-status' ? 'در حال ذخیره…' : 'غیرفعال کردن پروژه' }}</button><p v-else class="panel-copy">این پروژه به‌عنوان رکورد غیرفعال نگهداری شده است.</p></form></article>
      <article class="admin-panel"><h2>افزودن عضو</h2><p class="panel-copy">UUID یک کاربر موجود را وارد کنید. نقش و دامنه پروژه در سرور بررسی می‌شود.</p><form class="admin-form" @submit.prevent="createMembership"><label>شناسه کاربر<input v-model.trim="memberForm.user_id" dir="ltr" required :disabled="!!pending" placeholder="UUID"></label><fieldset><legend>نقش‌ها</legend><label v-for="role in roles" :key="role" class="check-row"><input v-model="memberForm.roles" :value="role" type="checkbox" :disabled="!!pending">{{ roleLabel(role) }}</label></fieldset><button class="button button-primary" :disabled="!!pending || !memberForm.roles.length">{{ pending === 'member-create' ? 'در حال افزودن…' : 'افزودن عضو' }}</button></form></article></section>
      <section class="admin-panel"><h2>اعضا و مجوزهای نقش</h2><AdminRemediationPanel v-if="membershipRemediation" kind="assignment" :message="membershipRemediation.message" :pending="pending === membershipRemediation.membership.id" @cancel="cancelMembershipRemediation" @unassign="remediateMembership({ unassign: true })" @reassign="remediateMembership({ reassign_to: $event })" /><p v-if="!memberships.length" class="panel-copy">هنوز عضوی وجود ندارد.</p><div v-else class="admin-table-wrap"><table class="admin-table"><thead><tr><th>شناسه کاربر</th><th>نقش‌ها</th><th>وضعیت</th><th>اقدام‌ها</th></tr></thead><tbody><tr v-for="membership in memberships" :key="membership.id"><td class="mono" dir="ltr">{{ membership.user_id }}</td><td><div class="role-list"><label v-for="role in roles" :key="role" class="check-row"><input v-model="roleSelections[membership.id]" :value="role" type="checkbox" :disabled="!!pending">{{ roleLabel(role) }}</label></div></td><td><AdminStatusBadge :active="membership.active" /></td><td class="action-stack"><button class="text-button" :disabled="!!pending" @click="saveMembership(membership)">{{ pending === membership.id ? 'در حال ذخیره…' : 'ذخیره نقش‌ها' }}</button><button v-if="membership.active" class="text-button" :disabled="!!pending" @click="deactivateMembership(membership)">{{ pending === membership.id ? 'در حال ذخیره…' : 'غیرفعال کردن' }}</button><span v-else class="muted">رکورد غیرفعال نگهداری شده</span></td></tr></tbody></table></div></section>
      <section class="admin-grid"><article class="admin-panel"><h2>افزودن مقدار قابل تنظیم</h2><form class="admin-form" @submit.prevent="createValue"><label>نوع<select v-model="valueForm.kind" :disabled="!!pending"><option v-for="kind in kinds" :key="kind" :value="kind">{{ trackingKindLabel(kind) }}</option></select></label><label>کد<input v-model.trim="valueForm.code" dir="ltr" required maxlength="60" :disabled="!!pending"></label><label>نام<input v-model.trim="valueForm.name" required :disabled="!!pending"></label><label>رتبه <span class="muted">اختیاری</span><input v-model="valueForm.rank" type="number" min="0" :disabled="!!pending"></label><button class="button button-primary" :disabled="!!pending">{{ pending === 'value-create' ? 'در حال افزودن…' : 'افزودن مقدار' }}</button></form></article>
        <article class="admin-panel admin-panel--wide"><h2>مقادیر قابل تنظیم</h2><AdminRemediationPanel v-if="valueRemediation" kind="tracking" :message="valueRemediation.message" :pending="pending === valueRemediation.value.id" :replacements="replacementValues" @cancel="valueRemediation = null" @unset="remediateValue({ unset: true })" @replace="remediateValue({ replace_with: $event })" /><p v-if="!trackingValues.length" class="panel-copy">هنوز مقداری وجود ندارد.</p><div v-else class="admin-table-wrap"><table class="admin-table"><thead><tr><th>نوع</th><th>مقدار</th><th>رتبه</th><th>وضعیت</th><th>اقدام</th></tr></thead><tbody><tr v-for="value in trackingValues" :key="value.id"><td>{{ trackingKindLabel(value.kind) }}</td><td><strong>{{ value.name }}</strong><small dir="ltr">{{ value.code }}</small></td><td>{{ value.rank ?? '—' }}</td><td><AdminStatusBadge :active="value.active" /></td><td><button v-if="value.active" class="text-button" :disabled="!!pending" @click="deactivateValue(value)">{{ pending === value.id ? 'در حال ذخیره…' : 'غیرفعال کردن' }}</button><span v-else class="muted">رکورد غیرفعال نگهداری شده</span></td></tr></tbody></table></div></article></section>
    </template>
  </main>
</template>
