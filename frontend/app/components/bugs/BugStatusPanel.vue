<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError } from '~/plugins/api.client'
import { bugErrorMessage, useBugs, type BugReport } from '~/composables/useBugs'
import { actionLabel, decisionLabel, formatDateTime, formatNumber, resolutionLabel, statusLabel } from '~/utils/presentation'

const props = defineProps<{ bug: BugReport }>()
const emit = defineEmits<{
  changed: [bug: BugReport]
  refresh: []
}>()

const bugs = useBugs()
const pending = ref('')
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const reopenReason = ref('')

const actions = computed(() => props.bug.allowed_actions)
const attempts = computed(() => props.bug.resolution_attempts || [])
const hasReopenedAction = computed(() => actions.value.includes('resume_work') || actions.value.includes('renew_review'))
const visible = computed(() => props.bug.status === 'reopened' || actions.value.includes('reopen') || attempts.value.length > 0)

async function mutate(name: string, operation: () => Promise<{ data: BugReport }>, clear?: () => void) {
  if (pending.value) return

  pending.value = name
  error.value = ''
  fieldErrors.value = {}

  try {
    const response = await operation()
    clear?.()
    emit('changed', response.data)
  } catch (caught) {
    if (caught instanceof ApiError) {
      fieldErrors.value = caught.fieldErrors
      if (caught.status === 409) emit('refresh')
    }

    if (!(caught instanceof ApiError) || caught.status !== 422 || !Object.keys(fieldErrors.value).length) {
      error.value = bugErrorMessage(caught)
    }
  } finally {
    pending.value = ''
  }
}
</script>

<template>
  <section v-if="visible" class="bug-panel workflow-panel phase-panel status-panel">
    <div class="panel-title">
      <div>
        <p class="eyebrow">چرخه عمر</p>
        <h2>وضعیت و تلاش‌های رفع</h2>
      </div>
      <span :class="['bug-status', `bug-status--${bug.status}`]">{{ statusLabel(bug.status) }}</span>
    </div>

    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <div v-if="bug.status === 'reopened'" class="status-guidance">
      <h3>نتیجه رفع رد شد</h3>
      <p v-if="hasReopenedAction">فقط اقدام بعدی قابل انجام برای حساب خود را استفاده کنید.</p>
      <p v-else>این باگ بازگشایی شده، اما فعلاً اقدام بعدی برای حساب شما مجاز نیست.</p>
    </div>

    <div v-if="actions.includes('resume_work')" class="workflow-block">
      <div>
        <h3>ادامه توسعه</h3>
        <p>پس از رد تلاش رفع، تخصیص فعلی را ادامه دهید. این دستور تخصیص را تغییر نمی‌دهد.</p>
      </div>
      <button class="button button-primary workflow-button" :disabled="!!pending" @click="mutate('resume', () => bugs.resumeWork(bug.public_id))">
        {{ pending === 'resume' ? 'در حال ادامه…' : actionLabel('resume_work') }}
      </button>
    </div>

    <div v-if="actions.includes('renew_review')" class="workflow-block">
      <div>
        <h3>بازگشت به بررسی مدیر</h3>
        <p>این باگ بازگشایی‌شده را به حالت بررسی بازمی‌گرداند. تخصیص توسعه‌دهنده اقدامی جداگانه است.</p>
      </div>
      <button class="button button-secondary workflow-button" :disabled="!!pending" @click="mutate('renew', () => bugs.renewReview(bug.public_id))">
        {{ pending === 'renew' ? 'در حال بازگشت…' : actionLabel('renew_review') }}
      </button>
    </div>

    <form v-if="actions.includes('reopen')" class="workflow-block workflow-form" @submit.prevent="mutate('reopen', () => bugs.reopen(bug.public_id, reopenReason), () => { reopenReason = '' })">
      <div>
        <h3>بازگشایی باگ بسته‌شده</h3>
        <p>دلیل نیاز به بررسی مجدد مدیر را ثبت کنید.</p>
      </div>
      <label for="reopen-reason">دلیل <span class="required-mark">الزامی</span></label>
      <textarea id="reopen-reason" v-model="reopenReason" rows="4" required :disabled="!!pending" :aria-invalid="!!fieldErrors.reason" aria-describedby="reopen-reason-error" />
      <p v-if="fieldErrors.reason?.[0]" id="reopen-reason-error" class="field-error">{{ fieldErrors.reason[0] }}</p>
      <button class="button button-secondary workflow-button" :disabled="!!pending">
        {{ pending === 'reopen' ? 'در حال بازگشایی…' : actionLabel('reopen') }}
      </button>
    </form>

    <div v-if="attempts.length" class="attempt-history">
      <div class="attempt-history-heading">
        <h3>تلاش‌های رفع</h3>
        <span>{{ formatNumber(attempts.length) }}</span>
      </div>
      <ol class="attempt-list">
        <li v-for="attempt in attempts" :key="attempt.id" class="attempt-card">
          <div class="attempt-card-heading">
            <div>
              <strong>تلاش {{ formatNumber(attempt.attempt_number) }} · {{ resolutionLabel(attempt.outcome) }}</strong>
              <span v-if="bug.active_resolution?.id === attempt.id" class="status-badge status-badge--active">فعال</span>
            </div>
            <time :datetime="attempt.recorded_at">{{ formatDateTime(attempt.recorded_at) }}</time>
          </div>
          <dl>
            <div>
              <dt>توضیحات</dt>
              <dd>{{ attempt.explanation }}</dd>
            </div>
            <div v-if="attempt.qa_instructions">
              <dt>دستورالعمل QA</dt>
              <dd>{{ attempt.qa_instructions }}</dd>
            </div>
            <div>
              <dt>رفع‌شده توسط</dt>
              <dd>{{ attempt.recorded_by.display_name }}</dd>
            </div>
          </dl>

          <div v-if="attempt.qa_result" class="qa-result" :class="`qa-result--${attempt.qa_result.decision}`">
            <div class="qa-result-heading">
              <strong>QA: {{ decisionLabel(attempt.qa_result.decision) }}</strong>
              <time :datetime="attempt.qa_result.created_at">{{ formatDateTime(attempt.qa_result.created_at) }}</time>
            </div>
            <p>{{ attempt.qa_result.verification_notes }}</p>
            <small>بررسی‌شده توسط {{ attempt.qa_result.verifier.display_name }}</small>
          </div>
          <p v-else class="muted attempt-pending">نتیجه QA ثبت نشده است.</p>
        </li>
      </ol>
    </div>
  </section>
</template>
