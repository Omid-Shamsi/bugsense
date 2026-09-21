<script setup lang="ts">
import type { ResolutionAttempt } from '~/composables/useBugs'
import { decisionLabel, formatDateTime, formatNumber } from '~/utils/presentation'
defineProps<{ attempt: ResolutionAttempt; active?: boolean; detailed?: boolean }>()
</script>

<template>
  <article :class="['resolution-attempt', { 'resolution-attempt--active': active }]">
    <header class="resolution-attempt__header">
      <div class="resolution-attempt__title">
        <strong>تلاش {{ formatNumber(attempt.attempt_number) }}</strong>
        <ResolutionOutcomeBadge :outcome="attempt.outcome" />
        <UBadge v-if="active" color="info" variant="subtle">فعال</UBadge>
      </div>
      <time :datetime="attempt.recorded_at">{{ formatDateTime(attempt.recorded_at) }}</time>
    </header>
    <dl class="resolution-attempt__details">
      <div><dt>توضیحات</dt><dd dir="auto">{{ attempt.explanation }}</dd></div>
      <div v-if="attempt.qa_instructions"><dt>دستورالعمل QA</dt><dd dir="auto">{{ attempt.qa_instructions }}</dd></div>
      <div v-if="attempt.reproduction_attempts"><dt>مراحل امتحان‌شده</dt><dd dir="auto">{{ attempt.reproduction_attempts }}</dd></div>
      <div v-if="attempt.reproduction_environment"><dt>محیط بازتولید</dt><dd dir="auto">{{ attempt.reproduction_environment }}</dd></div>
      <div v-if="attempt.decision_rationale"><dt>توجیه تصمیم</dt><dd dir="auto">{{ attempt.decision_rationale }}</dd></div>
      <div><dt>ثبت‌کننده</dt><dd><UserIdentity :name="attempt.recorded_by.display_name" :active="attempt.recorded_by.active" compact /></dd></div>
    </dl>
    <div v-if="attempt.qa_result" :class="['resolution-attempt__qa', `resolution-attempt__qa--${attempt.qa_result.decision}`]">
      <strong>تصمیم QA: {{ decisionLabel(attempt.qa_result.decision) }}</strong>
      <p dir="auto">{{ attempt.qa_result.verification_notes }}</p>
      <small><UserIdentity :name="attempt.qa_result.verifier.display_name" :active="attempt.qa_result.verifier.active" compact /> · {{ formatDateTime(attempt.qa_result.created_at) }}</small>
    </div>
    <p v-else-if="active" class="resolution-attempt__pending">در انتظار تصمیم QA</p>
  </article>
</template>

<style scoped>
.resolution-attempt { border-top: 1px solid var(--ui-border); padding: 1rem 0; }
.resolution-attempt--active { border-color: var(--ui-border-accented); }
.resolution-attempt__header, .resolution-attempt__title { align-items: center; display: flex; flex-wrap: wrap; gap: .45rem; justify-content: space-between; }
.resolution-attempt__title { justify-content: flex-start; }
.resolution-attempt__header time, .resolution-attempt small, .resolution-attempt__pending { color: var(--ui-text-muted); font-size: .75rem; }
.resolution-attempt__details { display: grid; gap: .75rem; grid-template-columns: repeat(2, minmax(0, 1fr)); margin: 1rem 0 0; }
.resolution-attempt__details div { min-width: 0; }
dt { color: var(--ui-text-muted); font-size: .75rem; } dd { margin: .2rem 0 0; overflow-wrap: anywhere; white-space: pre-wrap; }
.resolution-attempt__qa { border-inline-start: 1px solid var(--ui-border); margin-top: 1rem; padding-inline-start: .75rem; }
.resolution-attempt__qa--approved { color: var(--ui-success); }.resolution-attempt__qa--rejected { color: var(--ui-error); }
.resolution-attempt__qa p { color: var(--ui-text); margin: .35rem 0; white-space: pre-wrap; }
@media (max-width: 639px) { .resolution-attempt__details { grid-template-columns: 1fr; } }
</style>
