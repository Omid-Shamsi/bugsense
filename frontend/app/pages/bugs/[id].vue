<script setup lang="ts">
import { ApiError } from '~/plugins/api.client'
import { bugErrorMessage, bugToForm, useBugs, type BugFormData, type BugReport, type BugTrackingValue } from '~/composables/useBugs'
import { formatDateTime, statusLabel } from '~/utils/presentation'

definePageMeta({ middleware: 'auth' })
const route = useRoute()
const bugs = useBugs()
const bug = ref<BugReport | null>(null)
const trackingValues = ref<BugTrackingValue[]>([])
const loading = ref(true)
const editing = ref(false)
const pending = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const traceabilityRevision = ref(0)
const publicId = computed(() => String(route.params.id).toUpperCase())
const canEdit = computed(() => bug.value?.allowed_actions.includes('edit') || false)

async function load() {
  loading.value = true; error.value = ''
  try { bug.value = (await bugs.get(publicId.value)).data } catch (caught) { error.value = bugErrorMessage(caught) } finally { loading.value = false }
}

async function refreshAfterCommand(updated: BugReport) {
  bug.value = updated
  await refreshCurrentBug('عملیات موفق بود، اما به‌روزرسانی گزارش ناموفق بود. ')
}

async function refreshCurrentBug(errorPrefix = '') {
  error.value = ''
  try { bug.value = (await bugs.get(publicId.value)).data } catch (caught) { error.value = `${errorPrefix}${bugErrorMessage(caught)}` } finally { traceabilityRevision.value += 1 }
}

async function startEditing() {
  if (!bug.value || !canEdit.value) return
  editing.value = true; error.value = ''; fieldErrors.value = {}
  try { trackingValues.value = (await bugs.trackingValues(bug.value.project.key)).data } catch (caught) { error.value = bugErrorMessage(caught) }
}

async function update(value: BugFormData) {
  if (!bug.value || pending.value) return
  const original = bugToForm(bug.value)
  const changes: Record<string, unknown> = {}
  for (const field of ['title', 'description'] as const) if (value[field] !== original[field]) changes[field] = value[field]
  for (const field of ['steps_to_reproduce', 'expected_result', 'actual_result', 'environment', 'platform', 'application_version', 'category_id'] as const) if (value[field] !== original[field]) changes[field] = value[field] || null
  if ([...value.tag_ids].sort().join('|') !== [...original.tag_ids].sort().join('|')) changes.tag_ids = value.tag_ids

  if (!Object.keys(changes).length) { editing.value = false; return }
  pending.value = true; error.value = ''; fieldErrors.value = {}
  try {
    bug.value = (await bugs.update(publicId.value, changes)).data
    editing.value = false
    traceabilityRevision.value += 1
  } catch (caught) {
    if (caught instanceof ApiError) fieldErrors.value = caught.fieldErrors
    if (!(caught instanceof ApiError) || caught.status !== 422) error.value = bugErrorMessage(caught)
  } finally { pending.value = false }
}

await load()
</script>

<template>
  <main class="bug-page">
    <p v-if="loading" class="panel-copy">در حال بارگیری گزارش…</p>
    <div v-else-if="error && !bug" class="form-error" role="alert">{{ error }}</div>
    <template v-else-if="bug">
      <header class="bug-page-header"><div><p class="eyebrow">{{ bug.project.name }} · <bdi dir="ltr">{{ bug.project.key }}</bdi></p><div class="bug-title-row"><h1 dir="ltr">{{ bug.public_id }}</h1><span :class="['bug-status', `bug-status--${bug.status}`]">{{ statusLabel(bug.status) }}</span></div><p>{{ bug.title }}</p></div><button v-if="canEdit && !editing" class="admin-link button-reset" @click="startEditing">ویرایش گزارش</button></header>
      <p v-if="error && !editing" class="form-error" role="alert">{{ error }}</p>

      <section v-if="editing" class="bug-panel"><BugForm mode="edit" :allowed-actions="bug.allowed_actions" :initial-value="bugToForm(bug)" :tracking-values="trackingValues" :field-errors="fieldErrors" :error="error" :pending="pending" @submit="update"><template #attachments><AttachmentPanel embedded :bug="bug" @changed="traceabilityRevision += 1" /></template></BugForm><button class="text-button cancel-edit" :disabled="pending" @click="editing = false">انصراف</button></section>

      <template v-else>
        <section class="bug-detail-grid"><article class="bug-panel bug-report"><h2>گزارش</h2><div class="report-field"><h3>توضیحات</h3><p>{{ bug.description }}</p></div><div v-if="bug.steps_to_reproduce" class="report-field"><h3>مراحل بازتولید</h3><p>{{ bug.steps_to_reproduce }}</p></div><div v-if="bug.expected_result" class="report-field"><h3>نتیجه مورد انتظار</h3><p>{{ bug.expected_result }}</p></div><div v-if="bug.actual_result" class="report-field"><h3>نتیجه فعلی</h3><p>{{ bug.actual_result }}</p></div><div v-if="bug.environment" class="report-field"><h3>محیط</h3><p>{{ bug.environment }}</p></div></article>
          <aside class="bug-panel bug-meta"><h2>جزئیات</h2><dl><div><dt>گزارش‌دهنده</dt><dd>{{ bug.reporter.display_name }}</dd></div><div><dt>مسئول</dt><dd>{{ bug.assignee?.display_name || 'بدون مسئول' }}</dd></div><div><dt>دسته‌بندی</dt><dd>{{ bug.category?.name || 'تعیین‌نشده' }}</dd></div><div><dt>اولویت</dt><dd>{{ bug.priority?.name || 'تعیین‌نشده' }}</dd></div><div><dt>شدت</dt><dd>{{ bug.severity?.name || 'تعیین‌نشده' }}</dd></div><div><dt>سکو</dt><dd>{{ bug.platform || 'تعیین‌نشده' }}</dd></div><div><dt>نسخه برنامه</dt><dd dir="ltr">{{ bug.application_version || 'تعیین‌نشده' }}</dd></div><div><dt>زمان ایجاد</dt><dd>{{ formatDateTime(bug.created_at) }}</dd></div><div><dt>آخرین به‌روزرسانی</dt><dd>{{ formatDateTime(bug.updated_at) }}</dd></div></dl><div class="report-field"><h3>برچسب‌ها</h3><div v-if="bug.tags.length" class="bug-tags"><span v-for="tag in bug.tags" :key="tag.id">{{ tag.name }}</span></div><p v-else class="muted">بدون برچسب</p></div></aside>
        </section>
        <AttachmentPanel :bug="bug" @changed="traceabilityRevision += 1" />
        <div class="workflow-grid"><TriagePanel :bug="bug" @changed="refreshAfterCommand" /><AssignmentPanel :bug="bug" @changed="refreshAfterCommand" /></div>
        <ResolutionPanel :bug="bug" @changed="refreshAfterCommand" @refresh="refreshCurrentBug" />
        <VerificationPanel :bug="bug" @changed="refreshAfterCommand" @refresh="refreshCurrentBug" />
        <BugStatusPanel :bug="bug" @changed="refreshAfterCommand" @refresh="refreshCurrentBug" />
        <div class="traceability-grid">
          <RelationshipPanel :bug="bug" @changed="traceabilityRevision += 1" />
          <ActivityTimeline :bug-id="bug.public_id" :refresh-key="traceabilityRevision" />
        </div>
      </template>
    </template>
  </main>
</template>
