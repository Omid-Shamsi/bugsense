<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { bugErrorMessage, useBugs, type ActivityEvent, type ActivityPaginationMeta } from '~/composables/useBugs'
import { activityFieldLabel, activityLabel, displayValue, formatDateTime, formatNumber } from '~/utils/presentation'

const props = withDefaults(defineProps<{ bugId: string; refreshKey?: number }>(), { refreshKey: 0 })
const bugs = useBugs()
const events = ref<ActivityEvent[]>([])
const meta = ref<ActivityPaginationMeta | null>(null)
const loading = ref(false)
const loadingMore = ref(false)
const error = ref('')
let requestVersion = 0

const canLoadMore = computed(() => !!meta.value && meta.value.current_page < meta.value.last_page)

function formatValue(value: unknown, field = ''): string {
  if (value === null || value === undefined || value === '') return 'تعیین‌نشده'
  if (typeof value === 'boolean') return value ? 'بله' : 'خیر'
  if (typeof value === 'number') return formatNumber(value)
  if (typeof value === 'string') {
    return displayValue(value, field)
  }
  if (Array.isArray(value)) return value.length ? value.map((item) => formatValue(item, field)).join('، ') : 'هیچ‌کدام'
  if (typeof value === 'object') {
    const entries = Object.entries(value as Record<string, unknown>)
    return entries.length ? entries.map(([key, item]) => `${activityFieldLabel(key)}: ${formatValue(item, key)}`).join(' · ') : 'هیچ‌کدام'
  }
  return String(value)
}

function sameValue(left: unknown, right: unknown): boolean {
  return JSON.stringify(left) === JSON.stringify(right)
}

function changes(event: ActivityEvent) {
  const before = event.before || {}
  const after = event.after || {}
  const keys = [...new Set([...Object.keys(before), ...Object.keys(after)])]

  return keys
    .filter((key) => !(key in before && key in after && sameValue(before[key], after[key])))
    .map((key) => ({
      key,
      label: activityFieldLabel(key),
      hasBefore: key in before,
      hasAfter: key in after,
      before: formatValue(before[key], key),
      after: formatValue(after[key], key),
    }))
}

async function load(page = 1, append = false) {
  const version = append ? requestVersion : ++requestVersion
  if (append) loadingMore.value = true
  else {
    loading.value = true
    events.value = []
    meta.value = null
  }
  error.value = ''

  try {
    const response = await bugs.activity(props.bugId, page)
    if (version !== requestVersion) return
    const combined = append ? [...events.value, ...response.data] : response.data
    events.value = [...new Map(combined.map((event) => [event.sequence, event])).values()]
      .sort((left, right) => left.sequence - right.sequence)
    meta.value = response.meta
  } catch (caught) {
    if (version === requestVersion) error.value = bugErrorMessage(caught)
  } finally {
    if (version === requestVersion) {
      loading.value = false
      loadingMore.value = false
    }
  }
}

function retry() {
  if (events.value.length && meta.value) void load(meta.value.current_page + 1, true)
  else void load()
}

watch(() => [props.bugId, props.refreshKey], () => { void load() }, { immediate: true })
</script>

<template>
  <section class="bug-panel traceability-panel activity-panel" :aria-busy="loading || loadingMore">
    <div class="panel-title">
      <div>
        <p class="eyebrow">ردیابی</p>
        <h2>تاریخچه فعالیت</h2>
      </div>
      <span v-if="meta" class="traceability-count">{{ formatNumber(meta.total) }}</span>
    </div>

    <p v-if="loading" class="panel-copy">در حال بارگیری فعالیت‌ها…</p>
    <template v-else>
      <div v-if="error" class="form-error traceability-error" role="alert">
        <span>{{ error }}</span>
        <button class="text-button" type="button" :disabled="loadingMore" @click="retry">تلاش مجدد</button>
      </div>

      <p v-if="!events.length && !error" class="traceability-empty">فعالیتی برای این باگ ثبت نشده است.</p>

      <ol v-else-if="events.length" class="activity-list">
        <li v-for="event in events" :key="event.sequence" class="activity-item">
          <div class="activity-heading">
            <div>
              <span class="activity-sequence">#{{ event.sequence }}</span>
              <strong>{{ activityLabel(event.type) }}</strong>
            </div>
            <time :datetime="event.occurred_at">{{ formatDateTime(event.occurred_at) }}</time>
          </div>
          <p class="activity-actor">
            {{ event.actor?.display_name || 'سیستم' }}
            <span v-if="event.actor && !event.actor.active">(حساب غیرفعال)</span>
          </p>

          <dl v-if="changes(event).length" class="activity-changes">
            <div v-for="change in changes(event)" :key="change.key">
              <dt>{{ change.label }}</dt>
              <dd v-if="change.hasBefore && change.hasAfter">
                <span>{{ change.before }}</span><span class="change-arrow">به</span><strong>{{ change.after }}</strong>
              </dd>
              <dd v-else-if="change.hasAfter"><strong>{{ change.after }}</strong></dd>
              <dd v-else><span>{{ change.before }}</span><span class="muted"> (حذف‌شده)</span></dd>
            </div>
          </dl>
          <p v-if="event.reason_or_result" class="activity-reason"><span>دلیل / نتیجه</span>{{ event.reason_or_result }}</p>
          <p v-if="!changes(event).length && !event.reason_or_result" class="activity-no-context">جزئیات بیشتری ثبت نشده است.</p>
        </li>
      </ol>

      <div v-if="canLoadMore" class="timeline-more">
        <button class="button button-secondary" type="button" :disabled="loadingMore" @click="load((meta?.current_page || 0) + 1, true)">
          {{ loadingMore ? 'در حال بارگیری…' : 'بارگیری رویدادهای جدیدتر' }}
        </button>
      </div>
    </template>
  </section>
</template>
