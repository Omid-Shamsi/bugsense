<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ApiError } from '~/plugins/api.client'
import { bugErrorMessage, useBugs, type BugReport, type BugTrackingValue } from '~/composables/useBugs'
import { actionLabel, statusLabel } from '~/utils/presentation'

const props = defineProps<{ bug: BugReport; activeAction: 'begin_review' | 'request_information' | 'respond_information' | 'set_priority' | 'set_severity' }>()
const emit = defineEmits<{ changed: [bug: BugReport] }>()
const bugs = useBugs()
const pending = ref('')
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const requestText = ref('')
const responseText = ref('')
const priorityId = ref('')
const severityId = ref('')
const trackingValues = ref<BugTrackingValue[]>([])
const trackingLoading = ref(false)

const actions = computed(() => props.bug.allowed_actions)
const visible = computed(() => actions.value.includes(props.activeAction))
const priorities = computed(() => trackingValues.value.filter((value) => value.kind === 'priority' && value.active))
const severities = computed(() => trackingValues.value.filter((value) => value.kind === 'severity' && value.active))

watch(() => props.bug, (bug) => {
  priorityId.value = bug.priority?.id || ''
  severityId.value = bug.severity?.id || ''
  if (['set_priority', 'set_severity'].includes(props.activeAction) && bug.allowed_actions.includes(props.activeAction)) loadTrackingValues()
}, { immediate: true })

async function loadTrackingValues() {
  trackingLoading.value = true
  try {
    trackingValues.value = (await bugs.trackingValues(props.bug.project.key)).data
    if (!priorities.value.some((value) => value.id === priorityId.value)) priorityId.value = ''
    if (!severities.value.some((value) => value.id === severityId.value)) severityId.value = ''
  } catch (caught) { error.value = bugErrorMessage(caught) } finally { trackingLoading.value = false }
}

async function mutate(name: string, operation: () => Promise<{ data: BugReport }>, clear?: () => void) {
  if (pending.value) return
  pending.value = name; error.value = ''; fieldErrors.value = {}
  try {
    const response = await operation()
    clear?.()
    emit('changed', response.data)
  } catch (caught) {
    if (caught instanceof ApiError) fieldErrors.value = caught.fieldErrors
    if (!(caught instanceof ApiError) || caught.status !== 422 || !Object.keys(fieldErrors.value).length) error.value = bugErrorMessage(caught)
  } finally { pending.value = '' }
}
</script>

<template>
  <section v-if="visible" class="bug-panel workflow-panel">
    <div class="panel-title"><div><p class="eyebrow">بررسی اولیه</p><h2>تریاژ</h2></div><span :class="['bug-status', `bug-status--${bug.status}`]">{{ statusLabel(bug.status) }}</span></div>
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <div v-if="activeAction === 'begin_review' && actions.includes('begin_review')" class="workflow-block">
      <div><h3>شروع بررسی</h3><p>این گزارش ارسال‌شده را وارد مرحله بررسی مدیر می‌کند.</p></div>
      <button class="button button-primary workflow-button" :disabled="!!pending" @click="mutate('review', () => bugs.beginReview(bug.public_id))">{{ pending === 'review' ? 'در حال شروع…' : actionLabel('begin_review') }}</button>
    </div>

    <form v-if="activeAction === 'request_information' && actions.includes('request_information')" class="workflow-block workflow-form" @submit.prevent="mutate('request', () => bugs.requestInformation(bug.public_id, requestText), () => { requestText = '' })">
      <label for="information-request">درخواست اطلاعات بیشتر</label>
      <textarea id="information-request" v-model="requestText" rows="3" required :disabled="!!pending" :aria-invalid="!!fieldErrors.request" aria-describedby="information-request-error" />
      <p v-if="fieldErrors.request?.[0]" id="information-request-error" class="field-error">{{ fieldErrors.request[0] }}</p>
      <button class="button button-secondary workflow-button" :disabled="!!pending || !requestText.trim()">{{ pending === 'request' ? 'در حال ارسال…' : 'ارسال درخواست' }}</button>
    </form>

    <form v-if="activeAction === 'respond_information' && actions.includes('respond_information')" class="workflow-block workflow-form" @submit.prevent="mutate('response', () => bugs.respondInformation(bug.public_id, responseText), () => { responseText = '' })">
      <label for="information-response">پاسخ اطلاعاتی</label>
      <p>مدیر اطلاعات بیشتری درخواست کرده است. با افزودن پاسخ، گزارش به حالت بررسی بازمی‌گردد.</p>
      <textarea id="information-response" v-model="responseText" rows="4" required :disabled="!!pending" :aria-invalid="!!fieldErrors.response" aria-describedby="information-response-error" />
      <p v-if="fieldErrors.response?.[0]" id="information-response-error" class="field-error">{{ fieldErrors.response[0] }}</p>
      <button class="button button-primary workflow-button" :disabled="!!pending || !responseText.trim()">{{ pending === 'response' ? 'در حال ارسال…' : 'ارسال پاسخ' }}</button>
    </form>

    <div v-if="['set_priority', 'set_severity'].includes(activeAction)" class="classification-grid">
      <form v-if="activeAction === 'set_priority' && actions.includes('set_priority')" class="workflow-form" @submit.prevent="mutate('priority', () => bugs.setPriority(bug.public_id, priorityId))">
        <label for="bug-priority">اولویت</label>
        <select id="bug-priority" v-model="priorityId" :disabled="!!pending || trackingLoading" required :aria-invalid="!!fieldErrors.priority_id">
          <option value="" disabled>اولویت را انتخاب کنید</option><option v-for="value in priorities" :key="value.id" :value="value.id">{{ value.name }}</option>
        </select>
        <p v-if="fieldErrors.priority_id?.[0]" class="field-error">{{ fieldErrors.priority_id[0] }}</p>
        <button class="button button-secondary workflow-button" :disabled="!!pending || !priorityId">{{ pending === 'priority' ? 'در حال ذخیره…' : 'تعیین اولویت' }}</button>
      </form>
      <form v-if="activeAction === 'set_severity' && actions.includes('set_severity')" class="workflow-form" @submit.prevent="mutate('severity', () => bugs.setSeverity(bug.public_id, severityId))">
        <label for="bug-severity">شدت</label>
        <select id="bug-severity" v-model="severityId" :disabled="!!pending || trackingLoading" required :aria-invalid="!!fieldErrors.severity_id">
          <option value="" disabled>شدت را انتخاب کنید</option><option v-for="value in severities" :key="value.id" :value="value.id">{{ value.name }}</option>
        </select>
        <p v-if="fieldErrors.severity_id?.[0]" class="field-error">{{ fieldErrors.severity_id[0] }}</p>
        <button class="button button-secondary workflow-button" :disabled="!!pending || !severityId">{{ pending === 'severity' ? 'در حال ذخیره…' : 'تعیین شدت' }}</button>
      </form>
    </div>
  </section>
</template>
