<script setup lang="ts">
import { computed, ref } from 'vue'
import { ApiError } from '~/plugins/api.client'
import { bugErrorMessage, useBugs, type BugReport, type NonFixResolutionData } from '~/composables/useBugs'
import { actionLabel, formatDateTime, formatNumber, resolutionLabel, statusLabel } from '~/utils/presentation'

type NonFixOutcome = NonFixResolutionData['outcome']

const props = defineProps<{ bug: BugReport }>()
const emit = defineEmits<{
  changed: [bug: BugReport]
  refresh: []
}>()

const bugs = useBugs()
const pending = ref('')
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const progressBody = ref('')
const fixedExplanation = ref('')
const qaInstructions = ref('')
const nonFixOutcome = ref<NonFixOutcome>('duplicate')
const nonFixReason = ref('')
const duplicateBugId = ref('')
const attemptedSteps = ref('')
const reproductionEnvironment = ref('')
const decisionRationale = ref('')

const actions = computed(() => props.bug.allowed_actions)
const hasWorkflowAction = computed(() => actions.value.some((action) => [
  'start_work',
  'add_progress',
  'resolve_fixed',
  'resolve_non_fix',
].includes(action)))
const visible = computed(() => hasWorkflowAction.value || props.bug.active_resolution !== null)

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

function submitNonFix() {
  let payload: NonFixResolutionData

  if (nonFixOutcome.value === 'duplicate') {
    payload = {
      outcome: 'duplicate',
      reason: nonFixReason.value,
      duplicate_bug_id: duplicateBugId.value.trim().toUpperCase(),
    }
  } else if (nonFixOutcome.value === 'cannot_reproduce') {
    payload = {
      outcome: 'cannot_reproduce',
      reason: nonFixReason.value,
      attempted_steps: attemptedSteps.value,
      environment: reproductionEnvironment.value,
    }
  } else {
    payload = {
      outcome: 'wont_fix',
      reason: nonFixReason.value,
      decision_rationale: decisionRationale.value,
    }
  }

  return mutate('non-fix', () => bugs.resolveNonFix(props.bug.public_id, payload), () => {
    nonFixReason.value = ''
    duplicateBugId.value = ''
    attemptedSteps.value = ''
    reproductionEnvironment.value = ''
    decisionRationale.value = ''
  })
}
</script>

<template>
  <section v-if="visible" class="bug-panel workflow-panel resolution-panel">
    <div class="panel-title">
      <div>
        <p class="eyebrow">اجرا</p>
        <h2>کار و نتیجه رفع</h2>
      </div>
      <span :class="['bug-status', `bug-status--${bug.status}`]">{{ statusLabel(bug.status) }}</span>
    </div>

    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <article v-if="bug.active_resolution" class="active-resolution" aria-labelledby="active-resolution-title">
      <div class="active-resolution-heading">
        <div>
          <p class="eyebrow">نتیجه رفع فعال</p>
          <h3 id="active-resolution-title">تلاش {{ formatNumber(bug.active_resolution.attempt_number) }} · {{ resolutionLabel(bug.active_resolution.outcome) }}</h3>
        </div>
        <span :class="['bug-status', `bug-status--${bug.status}`]">{{ bug.status === 'qa_verification' ? 'در انتظار QA' : bug.status === 'closed' ? 'نتیجه رفع تأییدشده' : 'نتیجه رفع فعال' }}</span>
      </div>
      <dl>
        <div>
          <dt>توضیحات</dt>
          <dd>{{ bug.active_resolution.explanation }}</dd>
        </div>
        <div v-if="bug.active_resolution.qa_instructions">
          <dt>دستورالعمل QA</dt>
          <dd>{{ bug.active_resolution.qa_instructions }}</dd>
        </div>
        <div>
          <dt>ثبت‌شده توسط</dt>
          <dd>{{ bug.active_resolution.recorded_by.display_name }}</dd>
        </div>
        <div>
          <dt>زمان ثبت</dt>
          <dd>{{ formatDateTime(bug.active_resolution.recorded_at) }}</dd>
        </div>
      </dl>
      <p class="muted resolution-note">بررسی QA یک مرحله جداگانه است؛ این بخش باگ را تأیید یا بسته نمی‌کند.</p>
    </article>

    <div v-if="actions.includes('start_work')" class="workflow-block">
      <div>
        <h3>شروع کار</h3>
        <p>این باگ تخصیص‌یافته را با وضعیت فعلی سرور وارد حالت در حال انجام می‌کند.</p>
      </div>
      <button class="button button-primary workflow-button" :disabled="!!pending" @click="mutate('start', () => bugs.startWork(bug.public_id))">
        {{ pending === 'start' ? 'در حال شروع…' : actionLabel('start_work') }}
      </button>
    </div>

    <form v-if="actions.includes('add_progress')" class="workflow-block workflow-form" @submit.prevent="mutate('progress', () => bugs.addProgress(bug.public_id, progressBody), () => { progressBody = '' })">
      <label for="progress-body">گزارش پیشرفت <span class="required-mark">الزامی</span></label>
      <p>یک به‌روزرسانی توسعه اضافه کنید. گزارش‌های قبلی قابل ویرایش نیستند.</p>
      <textarea id="progress-body" v-model="progressBody" rows="4" required :disabled="!!pending" :aria-invalid="!!fieldErrors.body" aria-describedby="progress-body-error" />
      <p v-if="fieldErrors.body?.[0]" id="progress-body-error" class="field-error">{{ fieldErrors.body[0] }}</p>
      <button class="button button-secondary workflow-button" :disabled="!!pending">
        {{ pending === 'progress' ? 'در حال ثبت…' : actionLabel('add_progress') }}
      </button>
    </form>

    <form v-if="actions.includes('resolve_fixed')" class="workflow-block workflow-form" @submit.prevent="mutate('fixed', () => bugs.resolveFixed(bug.public_id, fixedExplanation, qaInstructions), () => { fixedExplanation = ''; qaInstructions = '' })">
      <div>
        <h3>ثبت به‌عنوان رفع‌شده</h3>
        <p>این کار یک تلاش رفع ثبت می‌کند و باگ را برای بررسی QA می‌فرستد. باگ در این مرحله بسته نمی‌شود.</p>
      </div>
      <label for="fixed-explanation">توضیحات <span class="required-mark">الزامی</span></label>
      <textarea id="fixed-explanation" v-model="fixedExplanation" rows="4" required :disabled="!!pending" :aria-invalid="!!fieldErrors.explanation" aria-describedby="fixed-explanation-error" />
      <p v-if="fieldErrors.explanation?.[0]" id="fixed-explanation-error" class="field-error">{{ fieldErrors.explanation[0] }}</p>

      <label for="qa-instructions">دستورالعمل QA <span class="required-mark">الزامی</span></label>
      <textarea id="qa-instructions" v-model="qaInstructions" rows="4" required :disabled="!!pending" :aria-invalid="!!fieldErrors.qa_instructions" aria-describedby="qa-instructions-error" />
      <p v-if="fieldErrors.qa_instructions?.[0]" id="qa-instructions-error" class="field-error">{{ fieldErrors.qa_instructions[0] }}</p>

      <button class="button button-primary workflow-button" :disabled="!!pending">
        {{ pending === 'fixed' ? 'در حال ثبت…' : actionLabel('record_fixed') }}
      </button>
    </form>

    <form v-if="actions.includes('resolve_non_fix')" class="workflow-block workflow-form" @submit.prevent="submitNonFix">
      <div>
        <h3>ثبت نتیجه غیراصلاحی</h3>
        <p>نتیجه را انتخاب و مدارک لازم برای آن را وارد کنید.</p>
      </div>

      <label for="non-fix-outcome">نتیجه <span class="required-mark">الزامی</span></label>
      <select id="non-fix-outcome" v-model="nonFixOutcome" required :disabled="!!pending" :aria-invalid="!!fieldErrors.outcome" aria-describedby="non-fix-outcome-error">
        <option value="duplicate">تکراری</option>
        <option value="cannot_reproduce">قابل بازتولید نیست</option>
        <option value="wont_fix">رفع نخواهد شد</option>
      </select>
      <p v-if="fieldErrors.outcome?.[0]" id="non-fix-outcome-error" class="field-error">{{ fieldErrors.outcome[0] }}</p>

      <label for="non-fix-reason">دلیل <span class="required-mark">الزامی</span></label>
      <textarea id="non-fix-reason" v-model="nonFixReason" rows="3" required :disabled="!!pending" :aria-invalid="!!fieldErrors.reason" aria-describedby="non-fix-reason-error" />
      <p v-if="fieldErrors.reason?.[0]" id="non-fix-reason-error" class="field-error">{{ fieldErrors.reason[0] }}</p>

      <template v-if="nonFixOutcome === 'duplicate'">
        <label for="duplicate-bug-id">شناسه عمومی باگ اصلی <span class="required-mark">الزامی</span></label>
        <input id="duplicate-bug-id" v-model="duplicateBugId" dir="ltr" type="text" placeholder="BUG-000123" required :disabled="!!pending" :aria-invalid="!!fieldErrors.duplicate_bug_id" aria-describedby="duplicate-bug-id-help duplicate-bug-id-error" @blur="duplicateBugId = duplicateBugId.trim().toUpperCase()">
        <p id="duplicate-bug-id-help" class="muted">شناسه عمومی باگ اصلی را وارد کنید، نه UUID داخلی آن را.</p>
        <p v-if="fieldErrors.duplicate_bug_id?.[0]" id="duplicate-bug-id-error" class="field-error">{{ fieldErrors.duplicate_bug_id[0] }}</p>
      </template>

      <template v-else-if="nonFixOutcome === 'cannot_reproduce'">
        <label for="attempted-steps">مراحل امتحان‌شده <span class="required-mark">الزامی</span></label>
        <textarea id="attempted-steps" v-model="attemptedSteps" rows="4" required :disabled="!!pending" :aria-invalid="!!fieldErrors.attempted_steps" aria-describedby="attempted-steps-error" />
        <p v-if="fieldErrors.attempted_steps?.[0]" id="attempted-steps-error" class="field-error">{{ fieldErrors.attempted_steps[0] }}</p>

        <label for="reproduction-environment">محیط <span class="required-mark">الزامی</span></label>
        <textarea id="reproduction-environment" v-model="reproductionEnvironment" rows="3" required :disabled="!!pending" :aria-invalid="!!fieldErrors.environment" aria-describedby="reproduction-environment-error" />
        <p v-if="fieldErrors.environment?.[0]" id="reproduction-environment-error" class="field-error">{{ fieldErrors.environment[0] }}</p>
      </template>

      <template v-else>
        <label for="decision-rationale">توجیه تصمیم <span class="required-mark">الزامی</span></label>
        <textarea id="decision-rationale" v-model="decisionRationale" rows="4" required :disabled="!!pending" :aria-invalid="!!fieldErrors.decision_rationale" aria-describedby="decision-rationale-error" />
        <p v-if="fieldErrors.decision_rationale?.[0]" id="decision-rationale-error" class="field-error">{{ fieldErrors.decision_rationale[0] }}</p>
      </template>

      <button class="button button-secondary workflow-button" :disabled="!!pending">
        {{ pending === 'non-fix' ? 'در حال ثبت…' : 'ثبت نتیجه غیراصلاحی' }}
      </button>
    </form>
  </section>
</template>
