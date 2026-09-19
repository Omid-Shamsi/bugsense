<script setup lang="ts">
import { ref } from 'vue'
import { ApiError } from '~/plugins/api.client'
import { bugErrorMessage, useBugs, type BugReport, type QAVerificationDecision } from '~/composables/useBugs'
import { actionLabel } from '~/utils/presentation'

const props = defineProps<{ bug: BugReport }>()
const emit = defineEmits<{
  changed: [bug: BugReport]
  refresh: []
}>()

const bugs = useBugs()
const decision = ref<QAVerificationDecision>('approved')
const notes = ref('')
const pending = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

async function submit() {
  if (pending.value) return

  pending.value = true
  error.value = ''
  fieldErrors.value = {}

  try {
    const response = await bugs.verify(props.bug.public_id, decision.value, notes.value)
    notes.value = ''
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
    pending.value = false
  }
}
</script>

<template>
  <section v-if="bug.allowed_actions.includes('verify')" class="bug-panel workflow-panel phase-panel verification-panel">
    <div class="panel-title">
      <div>
        <p class="eyebrow">بررسی QA</p>
        <h2>ثبت تصمیم QA</h2>
      </div>
      <span class="bug-status bug-status--qa_verification">بررسی QA</span>
    </div>

    <p class="panel-copy">نتیجه رفع فعال را بررسی و نتیجه مشاهده‌شده را ثبت کنید. صلاحیت QA و مستقل بودن بررسی‌کننده در سرور بررسی می‌شود.</p>
    <p v-if="error" class="form-error" role="alert">{{ error }}</p>

    <form class="workflow-form" @submit.prevent="submit">
      <fieldset class="decision-fieldset">
        <legend>تصمیم <span class="required-mark">الزامی</span></legend>
        <div class="decision-options">
          <label class="decision-option" :class="{ 'decision-option--selected': decision === 'approved' }">
            <input v-model="decision" type="radio" value="approved" :disabled="pending">
            <span><strong>تأییدشده</strong><small>QA نتیجه رفع را می‌پذیرد و سرور باگ را می‌بندد.</small></span>
          </label>
          <label class="decision-option" :class="{ 'decision-option--selected': decision === 'rejected' }">
            <input v-model="decision" type="radio" value="rejected" :disabled="pending">
            <span><strong>ردشده</strong><small>QA نتیجه رفع را رد می‌کند و سرور باگ را بازگشایی می‌کند.</small></span>
          </label>
        </div>
      </fieldset>
      <p v-if="fieldErrors.decision?.[0]" class="field-error">{{ fieldErrors.decision[0] }}</p>

      <label for="verification-notes">توضیحات بررسی <span class="required-mark">الزامی</span></label>
      <textarea id="verification-notes" v-model="notes" rows="5" required :disabled="pending" :aria-invalid="!!fieldErrors.notes" aria-describedby="verification-notes-error" />
      <p v-if="fieldErrors.notes?.[0]" id="verification-notes-error" class="field-error">{{ fieldErrors.notes[0] }}</p>

      <button class="button button-primary workflow-button" :disabled="pending">
        {{ pending ? 'در حال ثبت…' : decision === 'approved' ? actionLabel('approve_resolution') : actionLabel('reject_resolution') }}
      </button>
    </form>
  </section>
</template>
