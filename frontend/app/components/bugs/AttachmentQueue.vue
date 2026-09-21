<script setup lang="ts">
import { ref } from 'vue'
export type AttachmentQueueState = 'queued' | 'uploading' | 'uploaded' | 'failed'

export interface AttachmentQueueItem {
  id: string
  file: File
  state: AttachmentQueueState
  error?: string
}

const props = withDefaults(defineProps<{
  items: readonly AttachmentQueueItem[]
  canAdd?: boolean
  busy?: boolean
  selectionError?: string
  maxBytes?: number
}>(), { canAdd: true, busy: false, selectionError: '', maxBytes: 1_048_576 })

const emit = defineEmits<{
  add: [files: File[]]
  remove: [id: string]
  retry: [id: string]
  clearSelectionError: []
}>()

const input = ref<HTMLInputElement | null>(null)
const acceptedTypes = '.png,.jpg,.jpeg,.webp,.pdf,.txt,.log,image/png,image/jpeg,image/webp,application/pdf,text/plain'

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${new Intl.NumberFormat('fa-IR').format(bytes)} B`
  return `${new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 1 }).format(bytes / 1024 / 1024)} MB`
}

function onFilesSelected(event: Event): void {
  const files = Array.from((event.target as HTMLInputElement).files || [])
  if (files.length) emit('add', files)
  if (input.value) input.value.value = ''
}

function stateLabel(item: AttachmentQueueItem): string {
  if (item.state === 'queued') return 'در صف بارگذاری'
  if (item.state === 'uploading') return 'در حال بارگذاری'
  if (item.state === 'uploaded') return 'بارگذاری‌شده'
  return 'بارگذاری ناموفق'
}
</script>

<template>
  <section class="attachment-queue" aria-labelledby="attachment-queue-heading">
    <div class="attachment-queue__heading">
      <div>
        <h2 id="attachment-queue-heading" class="text-sm font-semibold text-highlighted">پیوست‌ها</h2>
        <p class="mt-1 text-xs leading-5 text-muted">فایل‌ها پس از ثبت باگ، جداگانه بارگذاری می‌شوند.</p>
      </div>
      <UButton
        v-if="canAdd"
        icon="i-lucide-paperclip"
        color="neutral"
        variant="soft"
        size="sm"
        type="button"
        :disabled="busy"
        @click="input?.click()"
      >
        انتخاب فایل
      </UButton>
    </div>

    <input
      ref="input"
      class="sr-only"
      type="file"
      multiple
      :accept="acceptedTypes"
      :disabled="busy || !canAdd"
      @change="onFilesSelected"
    >

    <p class="mt-3 text-xs leading-5 text-muted">
      PNG، JPEG، WebP، PDF، متن ساده و فایل وقایع؛ حداکثر {{ formatBytes(maxBytes) }} برای هر فایل. نوع و اندازه در سرور بررسی می‌شود.
    </p>

    <UAlert
      v-if="selectionError"
      class="mt-3"
      color="warning"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :description="selectionError"
      :close="{ onClick: () => emit('clearSelectionError') }"
    />

    <ul v-if="items.length" class="attachment-queue__list mt-3 divide-y divide-default border-y border-default">
      <li v-for="item in items" :key="item.id" class="attachment-queue__item py-3">
        <div class="min-w-0">
          <p class="truncate text-sm text-highlighted" dir="auto">{{ item.file.name }}</p>
          <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
            <bdi dir="ltr">{{ formatBytes(item.file.size) }}</bdi>
            <UBadge
              :color="item.state === 'failed' ? 'error' : item.state === 'uploaded' ? 'success' : 'neutral'"
              variant="subtle"
              size="xs"
            >
              <UIcon v-if="item.state === 'uploading'" name="i-lucide-loader-circle" class="me-1 animate-spin" aria-hidden="true" />
              {{ stateLabel(item) }}
            </UBadge>
          </div>
          <p v-if="item.error" class="mt-1 text-xs leading-5 text-error" role="alert">{{ item.error }}</p>
        </div>

        <div class="attachment-queue__actions shrink-0">
          <UButton
            v-if="item.state === 'failed'"
            color="neutral"
            variant="ghost"
            size="sm"
            type="button"
            :disabled="busy"
            @click="emit('retry', item.id)"
          >
            تلاش مجدد
          </UButton>
          <UButton
            v-if="item.state !== 'uploading' && item.state !== 'uploaded'"
            color="neutral"
            variant="ghost"
            size="sm"
            type="button"
            :disabled="busy"
            :aria-label="`حذف ${item.file.name}`"
            @click="emit('remove', item.id)"
          >
            حذف
          </UButton>
        </div>
      </li>
    </ul>

    <p v-else class="mt-3 text-xs text-muted">فایلی انتخاب نشده است.</p>
  </section>
</template>

<style scoped>
.attachment-queue__heading,
.attachment-queue__item,
.attachment-queue__actions {
  align-items: center;
  display: flex;
  gap: 0.75rem;
  justify-content: space-between;
}

@media (max-width: 40rem) {
  .attachment-queue__item {
    align-items: flex-start;
    flex-direction: column;
  }

  .attachment-queue__actions {
    width: 100%;
  }
}
</style>
