<script setup lang="ts">
import type { DashboardData, DashboardCounts } from '~/composables/useBugFilters'
import { formatDateTime, formatNumber, resolutionLabel, specialDisplayLabel } from '~/utils/presentation'

const props = defineProps<{ summary: DashboardData | null; loading: boolean; error: string }>()
defineEmits<{ retry: [] }>()

const countCards: { key: keyof DashboardCounts; label: string }[] = [
  { key: 'total', label: 'کل' },
  { key: 'open', label: 'باز' },
  { key: 'awaiting_review', label: 'در انتظار بررسی' },
  { key: 'awaiting_qa', label: 'در انتظار QA' },
  { key: 'resolved', label: 'رفع‌شده' },
  { key: 'qa_verification', label: 'بررسی QA' },
  { key: 'in_progress', label: 'در حال انجام' },
  { key: 'reopened', label: 'بازگشایی‌شده' },
  { key: 'closed', label: 'بسته‌شده' },
  { key: 'assigned_to_current_developer', label: 'تخصیص‌یافته به من' },
]

function duration(milliseconds: number): string {
  const totalMinutes = Math.max(0, Math.round(milliseconds / 60_000))
  if (totalMinutes < 1) return 'کمتر از ۱ دقیقه'
  const days = Math.floor(totalMinutes / 1440)
  const hours = Math.floor((totalMinutes % 1440) / 60)
  const minutes = totalMinutes % 60
  return [[days, 'روز'], [hours, 'ساعت'], [minutes, 'دقیقه']]
    .filter(([value]) => Number(value) > 0)
    .slice(0, 2)
    .map(([value, unit]) => `${formatNumber(Number(value))} ${unit}`)
    .join(' ')
}

</script>

<template>
  <section class="dashboard-results" :aria-busy="loading">
    <p v-if="loading" class="panel-copy" role="status">در حال بارگیری داشبورد…</p>
    <div v-else-if="error" class="form-error dashboard-error" role="alert">
      <span>{{ error }}</span>
      <button class="text-button" type="button" @click="$emit('retry')">تلاش مجدد</button>
    </div>
    <template v-else-if="summary">
      <div v-if="summary.counts.total === 0" class="work-empty dashboard-empty">
        <h3>باگی با این فیلترها مطابقت ندارد</h3>
        <p>خلاصه نتایج برای مجموعه فعلی قابل دسترسی، خالی است.</p>
      </div>

      <section class="dashboard-section" aria-labelledby="dashboard-counts-heading">
        <div class="dashboard-section-heading"><div><p class="eyebrow">مجموع سرور</p><h2 id="dashboard-counts-heading">تعدادها</h2></div><time :datetime="summary.context.generated_at">تولیدشده در {{ formatDateTime(summary.context.generated_at) }}</time></div>
        <div class="summary-card-grid">
          <article v-for="card in countCards" :key="card.key" class="summary-card">
            <span>{{ card.label }}</span><strong>{{ formatNumber(summary.counts[card.key]) }}</strong>
          </article>
        </div>
      </section>

      <section class="dashboard-section resolution-summary" aria-labelledby="resolution-time-heading">
        <div>
          <p class="eyebrow">زمان‌بندی نتیجه</p>
          <h2 id="resolution-time-heading">میانگین زمان رفع</h2>
          <p>از زمان ایجاد تا آخرین نتیجه رفع ثبت‌شده برای مجموعه واجد شرایط.</p>
        </div>
        <div class="resolution-value">
          <strong v-if="summary.average_resolution_time.sample_count > 0 && summary.average_resolution_time.milliseconds !== null">{{ duration(summary.average_resolution_time.milliseconds) }}</strong>
          <strong v-else>داده‌ای موجود نیست</strong>
          <span v-if="summary.average_resolution_time.sample_count > 0">{{ formatNumber(summary.average_resolution_time.sample_count) }} نمونه</span>
          <span v-else>نمونه تکمیل‌شده‌ای وجود ندارد</span>
        </div>
        <p class="resolution-outcomes">نتیجه‌های لحاظ‌شده: {{ summary.average_resolution_time.included_outcomes.map(resolutionLabel).join('، ') }}</p>
      </section>

      <section class="dashboard-section" aria-labelledby="dashboard-breakdowns-heading">
        <div class="dashboard-section-heading"><div><p class="eyebrow">ترکیب</p><h2 id="dashboard-breakdowns-heading">تفکیک</h2></div></div>
        <div class="breakdown-grid">
          <article class="breakdown-card"><h3>شدت</h3><ul><li v-for="row in summary.breakdowns.severity" :key="row.id || 'unset'"><span>{{ specialDisplayLabel(row.name) }}</span><strong>{{ formatNumber(row.count) }}</strong></li></ul><p v-if="!summary.breakdowns.severity.length" class="muted">باگ مطابقی وجود ندارد</p></article>
          <article class="breakdown-card"><h3>دسته‌بندی</h3><ul><li v-for="row in summary.breakdowns.category" :key="row.id || 'unset'"><span>{{ specialDisplayLabel(row.name) }}</span><strong>{{ formatNumber(row.count) }}</strong></li></ul><p v-if="!summary.breakdowns.category.length" class="muted">باگ مطابقی وجود ندارد</p></article>
          <article class="breakdown-card"><h3>پروژه</h3><ul><li v-for="row in summary.breakdowns.project" :key="row.id"><span><bdi dir="ltr">{{ row.key }}</bdi> · {{ row.name }}</span><strong>{{ formatNumber(row.count) }}</strong></li></ul><p v-if="!summary.breakdowns.project.length" class="muted">باگ مطابقی وجود ندارد</p></article>
          <article class="breakdown-card"><h3>توسعه‌دهنده</h3><ul><li v-for="row in summary.breakdowns.developer" :key="row.id || 'unassigned'"><span>{{ specialDisplayLabel(row.display_name) }}</span><strong>{{ formatNumber(row.count) }}</strong></li></ul><p v-if="!summary.breakdowns.developer.length" class="muted">باگ مطابقی وجود ندارد</p></article>
        </div>
      </section>
    </template>
  </section>
</template>
