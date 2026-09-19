<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { attachmentErrorMessage, useBugs, type BugAttachment, type BugReport } from '~/composables/useBugs'
import { actionLabel, formatDateTime, formatNumber } from '~/utils/presentation'

const props = withDefaults(defineProps<{
  bug: Pick<BugReport, 'public_id' | 'allowed_actions'>
  embedded?: boolean
}>(), { embedded: false })

const emit = defineEmits<{ changed: [] }>()
const bugs = useBugs()
const attachments = ref<BugAttachment[]>([])
const loading = ref(true)
const listError = ref('')
const actionError = ref('')
const selectedFile = ref<File | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const uploadPending = ref(false)
const removingId = ref<string | null>(null)
const downloadingId = ref<string | null>(null)
const canManage = computed(() => props.bug.allowed_actions.includes('edit'))
const mutationPending = computed(() => uploadPending.value || removingId.value !== null)

function formatBytes(bytes: number): string {
  const decimal = (value: number) => new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 1 }).format(value)
  if (bytes < 1024) return `${formatNumber(bytes)} B`
  if (bytes < 1024 * 1024) return `${decimal(bytes / 1024)} KB`
  return `${decimal(bytes / (1024 * 1024))} MB`
}

function formatDate(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? 'تاریخ نامشخص'
    : formatDateTime(value)
}

function typeLabel(attachment: BugAttachment): string {
  const labels: Record<string, string> = {
    'image/png': 'تصویر PNG',
    'image/jpeg': 'تصویر JPEG',
    'image/webp': 'تصویر WebP',
    'application/pdf': 'سند PDF',
    'text/plain': attachment.original_name.toLowerCase().endsWith('.log') ? 'فایل وقایع' : 'متن ساده',
  }
  return labels[attachment.detected_content_type] || attachment.detected_content_type || 'فایل'
}

async function load(): Promise<void> {
  loading.value = true
  listError.value = ''
  try {
    attachments.value = (await bugs.attachments(props.bug.public_id)).data
  } catch (error) {
    attachments.value = []
    listError.value = attachmentErrorMessage(error)
  } finally {
    loading.value = false
  }
}

function selectFile(event: Event): void {
  selectedFile.value = (event.target as HTMLInputElement).files?.[0] || null
  actionError.value = ''
}

async function upload(): Promise<void> {
  if (!selectedFile.value || mutationPending.value || !canManage.value) return

  uploadPending.value = true
  actionError.value = ''
  try {
    await bugs.uploadAttachment(props.bug.public_id, selectedFile.value)
    selectedFile.value = null
    if (fileInput.value) fileInput.value.value = ''
    await load()
    emit('changed')
  } catch (error) {
    actionError.value = attachmentErrorMessage(error)
  } finally {
    uploadPending.value = false
  }
}

async function download(attachment: BugAttachment): Promise<void> {
  if (attachment.state !== 'ready' || !attachment.download_url || downloadingId.value) return

  downloadingId.value = attachment.id
  actionError.value = ''
  try {
    const blob = await bugs.downloadAttachment(attachment.download_url)
    const objectUrl = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = objectUrl
    link.download = attachment.original_name
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 0)
  } catch (error) {
    actionError.value = attachmentErrorMessage(error)
  } finally {
    downloadingId.value = null
  }
}

async function remove(attachment: BugAttachment): Promise<void> {
  if (attachment.state !== 'ready' || mutationPending.value || !canManage.value) return

  removingId.value = attachment.id
  actionError.value = ''
  try {
    await bugs.removeAttachment(attachment.id)
    await load()
    emit('changed')
  } catch (error) {
    actionError.value = attachmentErrorMessage(error)
  } finally {
    removingId.value = null
  }
}

watch(() => props.bug.public_id, () => {
  selectedFile.value = null
  actionError.value = ''
  if (fileInput.value) fileInput.value.value = ''
  void load()
}, { immediate: true })
</script>

<template>
  <section :class="['attachment-panel', { 'bug-panel phase-panel': !embedded, 'attachment-panel--embedded': embedded }]">
    <div class="panel-title attachment-heading">
      <div>
        <p class="eyebrow">مدرک خصوصی</p>
        <h2>پیوست‌ها</h2>
      </div>
      <span v-if="!loading && !listError" class="traceability-count">{{ formatNumber(attachments.length) }}</span>
    </div>

    <p class="panel-copy">فایل‌ها با درخواست مجاز BugSense دانلود می‌شوند. دسترسی در هر درخواست دوباره بررسی می‌شود.</p>

    <div v-if="listError" class="traceability-error">
      <p class="form-error" role="alert">{{ listError }}</p>
      <button class="text-button" type="button" @click="load">تلاش مجدد برای فهرست پیوست‌ها</button>
    </div>
    <p v-else-if="loading" class="panel-copy" aria-live="polite">در حال بارگیری مدارک…</p>
    <p v-else-if="!attachments.length" class="traceability-empty">هنوز مدرکی پیوست نشده است.</p>
    <ul v-else class="attachment-list">
      <li v-for="attachment in attachments" :key="attachment.id" class="attachment-item">
        <div class="attachment-copy">
          <strong dir="auto">{{ attachment.original_name }}</strong>
          <span>{{ typeLabel(attachment) }} · {{ formatBytes(attachment.byte_size) }}</span>
          <small>بارگذاری‌شده در {{ formatDate(attachment.created_at) }}<template v-if="attachment.uploaded_by"> توسط {{ attachment.uploaded_by.display_name }}</template></small>
        </div>
        <div class="attachment-actions">
          <button
            v-if="attachment.state === 'ready' && attachment.download_url"
            class="text-button"
            type="button"
            :disabled="downloadingId !== null"
            @click="download(attachment)"
          >{{ downloadingId === attachment.id ? 'در حال دانلود…' : actionLabel('download') }}</button>
          <span v-else class="status-badge status-badge--inactive">حذف‌شده</span>
          <button
            v-if="canManage && attachment.state === 'ready'"
            class="text-button text-button--danger"
            type="button"
            :disabled="mutationPending"
            @click="remove(attachment)"
          >{{ removingId === attachment.id ? 'در حال حذف…' : actionLabel('remove') }}</button>
        </div>
      </li>
    </ul>

    <div v-if="actionError" class="form-error attachment-action-error" role="alert">{{ actionError }}</div>

    <div v-if="canManage" class="attachment-upload">
      <div class="field">
        <label :for="`bug-attachment-${bug.public_id}`">افزودن مدرک</label>
        <div class="attachment-file-picker">
          <input
            :id="`bug-attachment-${bug.public_id}`"
            ref="fileInput"
            class="attachment-file-input"
            type="file"
            accept=".png,.jpg,.jpeg,.webp,.pdf,.txt,.log,image/png,image/jpeg,image/webp,application/pdf,text/plain"
            :disabled="mutationPending"
            @change="selectFile"
          >
          <label class="button button-secondary attachment-file-trigger" :for="`bug-attachment-${bug.public_id}`">انتخاب فایل</label>
          <span class="attachment-file-name" dir="auto">{{ selectedFile?.name || 'فایلی انتخاب نشده است' }}</span>
        </div>
        <p class="attachment-help">فایل‌های PNG، JPEG، WebP، PDF، متن و وقایع پشتیبانی می‌شوند. اندازه و نوع فایل در سرور بررسی می‌شود.</p>
      </div>
      <button class="button button-primary attachment-upload-button" type="button" :disabled="!selectedFile || mutationPending" @click="upload">
        {{ uploadPending ? 'در حال بارگذاری…' : actionLabel('upload_evidence') }}
      </button>
    </div>
  </section>
</template>
