<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ApiError } from '~/plugins/api.client'
import { adminErrorMessage, useAdministration, type AdminMembership } from '~/composables/useAdministration'
import { bugErrorMessage, useBugs, type BugReport } from '~/composables/useBugs'

const props = defineProps<{ bug: BugReport }>()
const emit = defineEmits<{ changed: [bug: BugReport] }>()
const bugs = useBugs()
const admin = useAdministration()
const memberships = ref<AdminMembership[]>([])
const assigneeId = ref('')
const loading = ref(false)
const pending = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const candidates = computed(() => memberships.value.filter((membership) => membership.active && membership.roles.includes('developer')))

watch(() => props.bug, (bug) => {
  assigneeId.value = bug.assignee?.id || ''
  if (bug.allowed_actions.includes('assign')) loadCandidates()
}, { immediate: true })

async function loadCandidates() {
  loading.value = true; error.value = ''
  try {
    memberships.value = (await admin.listMemberships(props.bug.project.key)).data
    if (!candidates.value.some((membership) => membership.user_id === assigneeId.value)) assigneeId.value = ''
  } catch (caught) { error.value = adminErrorMessage(caught) } finally { loading.value = false }
}

async function submit() {
  if (pending.value || !assigneeId.value) return
  pending.value = true; error.value = ''; fieldErrors.value = {}
  try { emit('changed', (await bugs.assign(props.bug.public_id, assigneeId.value)).data) } catch (caught) {
    if (caught instanceof ApiError) fieldErrors.value = caught.fieldErrors
    if (!(caught instanceof ApiError) || caught.status !== 422 || !Object.keys(fieldErrors.value).length) error.value = bugErrorMessage(caught)
  } finally { pending.value = false }
}
</script>

<template>
  <section v-if="bug.allowed_actions.includes('assign')" class="bug-panel workflow-panel">
    <div class="panel-title"><div><p class="eyebrow">مالکیت</p><h2>تخصیص</h2></div></div>
    <p class="panel-copy">مسئول فعلی: <strong>{{ bug.assignee?.display_name || 'بدون مسئول' }}</strong></p>
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>
    <form class="workflow-form" @submit.prevent="submit">
      <label for="bug-assignee">توسعه‌دهنده واجد شرایط</label>
      <select id="bug-assignee" v-model="assigneeId" :disabled="loading || pending" required :aria-invalid="!!fieldErrors.assignee_id" aria-describedby="bug-assignee-error">
        <option value="" disabled>{{ loading ? 'در حال بارگیری توسعه‌دهندگان…' : 'توسعه‌دهنده را انتخاب کنید' }}</option>
        <option v-for="membership in candidates" :key="membership.id" :value="membership.user_id">{{ membership.user_display_name }} · {{ membership.user_id }}{{ membership.user_id === bug.assignee?.id ? ' (فعلی)' : '' }}</option>
      </select>
      <p class="muted candidate-note">نام عضو و شناسهٔ فنی او از منبع عضویت نمایش داده می‌شود. واجد شرایط بودن در سرور بررسی می‌شود.</p>
      <p v-if="fieldErrors.assignee_id?.[0]" id="bug-assignee-error" class="field-error">{{ fieldErrors.assignee_id[0] }}</p>
      <button class="button button-primary workflow-button" :disabled="loading || pending || !assigneeId">{{ pending ? 'در حال تخصیص…' : bug.assignee ? 'تخصیص مجدد توسعه‌دهنده' : 'تخصیص توسعه‌دهنده' }}</button>
    </form>
  </section>
</template>
