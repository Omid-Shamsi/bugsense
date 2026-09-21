<script setup lang="ts">
import { ApiError } from '~/plugins/api.client'
import {
  attachmentErrorMessage,
  bugErrorMessage,
  emptyBugForm,
  useBugs,
  type BugFormData,
  type BugReport,
  type BugTrackingValue,
} from '~/composables/useBugs'
import type { VisibleProject } from '~/composables/useWorkspace'
import AttachmentQueue, { type AttachmentQueueItem } from '~/components/bugs/AttachmentQueue.vue'

definePageMeta({ middleware: 'auth' })

type TrackingState = 'idle' | 'loading' | 'ready' | 'error'

const MAX_ATTACHMENT_BYTES = 1_048_576
const acceptedExtensions = new Set(['png', 'jpg', 'jpeg', 'webp', 'pdf', 'txt', 'log'])

const api = useApi()
const bugs = useBugs()
const session = useSession()
const workspace = useWorkspace()
const router = useRouter()
const toast = useToast()

const form = reactive<BugFormData>(emptyBugForm())
const visibleProjects = ref<VisibleProject[]>([])
const projectsLoading = ref(true)
const projectsError = ref('')
const trackingValues = ref<BugTrackingValue[]>([])
const trackingState = ref<TrackingState>('idle')
const trackingError = ref('')
const createError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const creating = ref(false)
const createdBug = ref<BugReport | null>(null)
const attachments = ref<AttachmentQueueItem[]>([])
const attachmentSelectionError = ref('')
let trackingRequestVersion = 0

const eligibleProjects = computed(() => {
  const memberships = session.user.value?.memberships || []
  const reporterProjectIds = new Set(
    memberships
      .filter((membership) => membership.active && membership.project.active && membership.roles.includes('reporter'))
      .map((membership) => membership.project.id),
  )

  return visibleProjects.value.filter((project) => project.active && reporterProjectIds.has(project.id))
})

const selectedProject = computed(() => eligibleProjects.value.find((project) => project.id === form.project_id) || null)
const categories = computed(() => trackingValues.value.filter((value) => value.kind === 'category' && value.active && value.project_id === form.project_id))
const tags = computed(() => trackingValues.value.filter((value) => value.kind === 'tag' && value.active && value.project_id === form.project_id))
const projectOptions = computed(() => eligibleProjects.value.map((project) => ({ label: project.name, value: project.id, description: project.key })))
const categoryOptions = computed(() => [
  { label: 'بدون دسته‌بندی', value: '' },
  ...categories.value.map((category) => ({ label: category.name, value: category.id })),
])
const tagOptions = computed(() => tags.value.map((tag) => ({ label: tag.name, value: tag.id })))
const selectedTags = computed(() => tags.value.filter((tag) => form.tag_ids.includes(tag.id)))
const shownTags = computed(() => selectedTags.value.slice(0, 4))
const hiddenTagCount = computed(() => Math.max(0, selectedTags.value.length - shownTags.value.length))
const trackingUnavailable = computed(() => !form.project_id || trackingState.value === 'loading' || trackingState.value === 'error')
const hasAttachmentFailures = computed(() => attachments.value.some((item) => item.state === 'failed'))
const allAttachmentsResolved = computed(() => attachments.value.length > 0 && attachments.value.every((item) => item.state === 'uploaded'))
const titleCountVisible = computed(() => form.title.length >= 160)

function fieldError(name: string): string | undefined {
  if (name === 'tag_ids') return fieldErrors.value.tag_ids?.[0] || fieldErrors.value['tag_ids.0']?.[0]
  return fieldErrors.value[name]?.[0]
}

function clearFieldError(name: keyof BugFormData): void {
  const next = { ...fieldErrors.value }
  delete next[name]
  if (name === 'tag_ids') Object.keys(next).filter((key) => key.startsWith('tag_ids.')).forEach((key) => delete next[key])
  fieldErrors.value = next
}

function clearTrackingSelections(): void {
  form.category_id = ''
  form.tag_ids = []
  clearFieldError('category_id')
  clearFieldError('tag_ids')
}

function sortTrackingValues(values: BugTrackingValue[]): BugTrackingValue[] {
  return [...values].sort((left, right) => {
    if (left.rank !== null && right.rank !== null && left.rank !== right.rank) return left.rank - right.rank
    if (left.rank !== null) return -1
    if (right.rank !== null) return 1
    return left.name.localeCompare(right.name, 'fa')
  })
}

async function loadProjects(): Promise<void> {
  projectsLoading.value = true
  projectsError.value = ''
  try {
    visibleProjects.value = (await api.request<{ data: VisibleProject[] }>('/projects')).data
  } catch (caught) {
    visibleProjects.value = []
    projectsError.value = bugErrorMessage(caught)
  } finally {
    projectsLoading.value = false
  }
}

async function loadTrackingValues(projectId: string): Promise<void> {
  const project = eligibleProjects.value.find((item) => item.id === projectId)
  const version = ++trackingRequestVersion
  trackingValues.value = []
  trackingError.value = ''

  if (!project) {
    trackingState.value = 'idle'
    return
  }

  trackingState.value = 'loading'
  try {
    const response = await bugs.trackingValues(project.key)
    if (version !== trackingRequestVersion || form.project_id !== projectId) return
    trackingValues.value = sortTrackingValues(response.data.filter((value) => value.project_id === projectId && value.active && ['category', 'tag'].includes(value.kind)))
    trackingState.value = 'ready'
  } catch (caught) {
    if (version !== trackingRequestVersion || form.project_id !== projectId) return
    trackingValues.value = []
    trackingError.value = bugErrorMessage(caught)
    trackingState.value = 'error'
  }
}

function selectInitialProject(): void {
  if (form.project_id && eligibleProjects.value.some((project) => project.id === form.project_id)) return

  const workspaceProject = workspace.selectedProject.value
  const preferred = workspaceProject && eligibleProjects.value.find((project) => project.id === workspaceProject.id)
  const nextProject = preferred || (eligibleProjects.value.length === 1 ? eligibleProjects.value[0] : null)
  form.project_id = nextProject?.id || ''
}

function focusFirstInvalidField(errors: Record<string, string[]>): void {
  const ordered = ['project_id', 'title', 'description', 'category_id', 'tag_ids']
  const target = ordered.find((name) => errors[name]?.length || (name === 'tag_ids' && Object.keys(errors).some((key) => key.startsWith('tag_ids.'))))
  if (!target) return
  void nextTick(() => document.getElementById(`report-${target}`)?.focus())
}

function validate(): boolean {
  const errors: Record<string, string[]> = {}
  if (!eligibleProjects.value.some((project) => project.id === form.project_id)) errors.project_id = ['یک پروژهٔ فعال با نقش گزارش‌دهنده انتخاب کنید.']
  if (!form.title.trim()) errors.title = ['عنوان باگ الزامی است.']
  else if (form.title.length > 200) errors.title = ['عنوان نمی‌تواند بیش از ۲۰۰ نویسه باشد.']
  if (!form.description.trim()) errors.description = ['توضیحات باگ الزامی است.']
  fieldErrors.value = errors
  if (Object.keys(errors).length) focusFirstInvalidField(errors)
  return !Object.keys(errors).length
}

function fileId(): string {
  return globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`
}

function addAttachments(files: File[]): void {
  const accepted: AttachmentQueueItem[] = []
  const rejected: string[] = []

  for (const file of files) {
    const extension = file.name.includes('.') ? file.name.split('.').pop()?.toLowerCase() || '' : ''
    if (!acceptedExtensions.has(extension)) rejected.push(`«${file.name}» نوع قابل‌قبولی ندارد.`)
    else if (file.size > MAX_ATTACHMENT_BYTES) rejected.push(`«${file.name}» از حداکثر ۱ مگابایت بزرگ‌تر است.`)
    else if (file.size === 0) rejected.push(`«${file.name}» خالی است.`)
    else accepted.push({ id: fileId(), file, state: 'queued' })
  }

  attachments.value = [...attachments.value, ...accepted]
  attachmentSelectionError.value = rejected.join(' ')
}

function removeAttachment(id: string): void {
  attachments.value = attachments.value.filter((item) => item.id !== id)
}

function setAttachment(id: string, patch: Partial<AttachmentQueueItem>): void {
  attachments.value = attachments.value.map((item) => item.id === id ? { ...item, ...patch } : item)
}

async function uploadAttachment(id: string): Promise<void> {
  if (!createdBug.value) return
  const item = attachments.value.find((candidate) => candidate.id === id)
  if (!item || item.state === 'uploading' || item.state === 'uploaded') return

  setAttachment(id, { state: 'uploading', error: undefined })
  try {
    await bugs.uploadAttachment(createdBug.value.public_id, item.file)
    setAttachment(id, { state: 'uploaded' })
  } catch (caught) {
    setAttachment(id, { state: 'failed', error: attachmentErrorMessage(caught) })
  }
}

async function uploadQueuedAttachments(): Promise<void> {
  for (const item of attachments.value) if (item.state === 'queued') await uploadAttachment(item.id)

  if (allAttachmentsResolved.value) {
    toast.add({ title: `باگ ${createdBug.value?.public_id} ثبت و پیوست‌ها بارگذاری شدند.` })
    await router.push(`/bugs/${createdBug.value?.public_id}`)
  }
}

async function retryAttachment(id: string): Promise<void> {
  await uploadAttachment(id)
  if (allAttachmentsResolved.value) {
    toast.add({ title: `همهٔ پیوست‌های ${createdBug.value?.public_id} بارگذاری شدند.` })
    await router.push(`/bugs/${createdBug.value?.public_id}`)
  }
}

async function submit(): Promise<void> {
  if (creating.value || createdBug.value || !validate()) return

  creating.value = true
  createError.value = ''
  try {
    const created = await bugs.create({ ...form, tag_ids: [...form.tag_ids] })
    createdBug.value = created.data
    if (!attachments.value.length) {
      toast.add({ title: `باگ ${created.data.public_id} ثبت شد.` })
      await router.push(`/bugs/${created.data.public_id}`)
      return
    }
    await uploadQueuedAttachments()
  } catch (caught) {
    if (caught instanceof ApiError && caught.status === 422) {
      fieldErrors.value = caught.fieldErrors
      focusFirstInvalidField(caught.fieldErrors)
    } else {
      createError.value = bugErrorMessage(caught)
      if (caught instanceof ApiError && [403, 404].includes(caught.status || 0)) {
        if (caught.status === 404) clearTrackingSelections()
        await loadProjects()
      }
    }
  } finally {
    creating.value = false
  }
}

watch(eligibleProjects, () => selectInitialProject(), { immediate: true })
watch(() => workspace.selectedProject.value?.id, () => selectInitialProject())
watch(() => form.project_id, (projectId, previousProjectId) => {
  if (projectId === previousProjectId) return
  clearTrackingSelections()
  void loadTrackingValues(projectId)
})

await loadProjects()
</script>

<template>
  <main class="report-bug-page" :aria-busy="projectsLoading || creating">
    <div v-if="projectsLoading" class="report-bug-page__skeleton" role="status" aria-live="polite">
      <span class="sr-only">در حال آماده‌سازی فرم ثبت باگ…</span>
      <USkeleton class="h-5 w-32" />
      <USkeleton class="h-10 w-full" />
      <USkeleton class="h-5 w-40" />
      <USkeleton class="h-10 w-full" />
      <USkeleton class="h-32 w-full" />
    </div>

    <section v-else-if="projectsError" class="report-bug-page__state" aria-labelledby="report-project-error">
      <UAlert id="report-project-error" color="error" variant="subtle" icon="i-lucide-circle-alert" title="بارگیری پروژه‌ها انجام نشد" :description="projectsError" />
      <UButton color="neutral" variant="soft" class="mt-3" type="button" @click="loadProjects">تلاش مجدد</UButton>
    </section>

    <section v-else-if="!eligibleProjects.length" class="report-bug-page__state" aria-labelledby="report-no-projects">
      <UAlert id="report-no-projects" color="neutral" variant="subtle" icon="i-lucide-folder-x" title="پروژه‌ای برای ثبت باگ در دسترس نیست." description="برای ثبت باگ، حساب شما به عضویت فعال با نقش گزارش‌دهنده در یک پروژهٔ فعال نیاز دارد." />
      <div class="mt-3 flex flex-wrap gap-2">
        <UButton to="/bugs" color="neutral" variant="soft">بازگشت به فهرست باگ‌ها</UButton>
        <UButton to="/dashboard" color="neutral" variant="ghost">داشبورد</UButton>
      </div>
    </section>

    <form v-else class="report-bug-form" novalidate @submit.prevent="submit">
      <p class="text-sm leading-6 text-muted">مشکل را با جزئیات کافی برای بازتولید ثبت کنید.</p>

      <UAlert v-if="createError" class="mt-4" color="error" variant="subtle" icon="i-lucide-circle-alert" title="ثبت باگ انجام نشد" :description="createError">
        <template #actions><UButton color="error" variant="soft" size="sm" type="button" :disabled="creating" @click="submit">تلاش مجدد</UButton></template>
      </UAlert>

      <UAlert v-if="createdBug && hasAttachmentFailures" class="mt-4" color="warning" variant="subtle" icon="i-lucide-triangle-alert" title="باگ ثبت شد، اما بعضی پیوست‌ها بارگذاری نشدند.">
        <template #description><span>باگ <bdi dir="ltr">{{ createdBug.public_id }}</bdi> ذخیره شده است. فقط پیوست‌های ناموفق را دوباره تلاش کنید.</span></template>
        <template #actions><UButton :to="`/bugs/${createdBug.public_id}`" color="neutral" variant="soft" size="sm">مشاهده باگ</UButton></template>
      </UAlert>

      <UAlert v-else-if="createdBug" class="mt-4" color="success" variant="subtle" icon="i-lucide-check-circle-2" title="باگ ثبت شد؛ در حال بارگذاری پیوست‌ها…">
        <template #description><span>شناسهٔ باگ: <bdi dir="ltr">{{ createdBug.public_id }}</bdi></span></template>
      </UAlert>

      <section class="report-bug-form__section" aria-labelledby="report-scope-heading">
        <div class="report-bug-form__section-heading"><h2 id="report-scope-heading">دامنهٔ پروژه</h2><p>دسته‌بندی و برچسب‌ها به پروژهٔ انتخاب‌شده تعلق دارند.</p></div>
        <div class="report-bug-form__grid">
          <UFormField name="project_id" label="پروژه" required :error="fieldError('project_id')" class="report-bug-form__project" :ui="{ container: 'w-full' }">
            <template #label="{ label }"><span id="report-project_id-label">{{ label }}</span></template>
            <USelectMenu id="report-project_id" v-model="form.project_id" aria-labelledby="report-project_id-label" :items="projectOptions" value-key="value" placeholder="پروژه را انتخاب کنید" :disabled="creating || !!createdBug" :search-input="false" class="w-full" @update:model-value="clearFieldError('project_id')">
              <template #default><span v-if="selectedProject" class="flex min-w-0 items-center gap-2"><span class="truncate">{{ selectedProject.name }}</span><bdi dir="ltr" class="shrink-0 text-xs text-muted">{{ selectedProject.key }}</bdi></span><span v-else class="text-muted">پروژه را انتخاب کنید</span></template>
              <template #item-description="{ item }"><bdi dir="ltr">{{ item.description }}</bdi></template>
            </USelectMenu>
          </UFormField>

          <UFormField name="category_id" label="دسته‌بندی" :error="fieldError('category_id')" class="report-bug-form__category" :ui="{ container: 'w-full' }">
            <template #label="{ label }"><span id="report-category_id-label">{{ label }}</span></template>
            <USkeleton v-if="trackingState === 'loading'" class="h-10 w-full" />
            <USelectMenu v-else id="report-category_id" v-model="form.category_id" aria-labelledby="report-category_id-label" :items="categoryOptions" value-key="value" :disabled="trackingUnavailable || creating || !!createdBug" placeholder="بدون دسته‌بندی" :search-input="categories.length > 8" class="w-full" @update:model-value="clearFieldError('category_id')" />
          </UFormField>

          <UFormField name="tag_ids" label="برچسب‌ها" :error="fieldError('tag_ids')" class="report-bug-form__tags" :ui="{ container: 'w-full' }">
            <template #label="{ label }"><span id="report-tag_ids-label">{{ label }}</span></template>
            <span id="report-tag_ids-value" class="sr-only">{{ selectedTags.map((tag) => tag.name).join('، ') || 'بدون برچسب' }}</span>
            <USkeleton v-if="trackingState === 'loading'" class="h-10 w-full" />
            <USelectMenu v-else id="report-tag_ids" v-model="form.tag_ids" :items="tagOptions" value-key="value" multiple :disabled="trackingUnavailable || creating || !!createdBug" placeholder="بدون برچسب" :search-input="true" aria-labelledby="report-tag_ids-label report-tag_ids-value" class="w-full" @update:model-value="clearFieldError('tag_ids')">
              <template #default><span v-if="shownTags.length" class="flex min-w-0 flex-wrap items-center gap-1"><UBadge v-for="tag in shownTags" :key="tag.id" color="neutral" variant="subtle" size="xs">{{ tag.name }}</UBadge><UBadge v-if="hiddenTagCount" color="neutral" variant="subtle" size="xs">+{{ hiddenTagCount }}</UBadge></span><span v-else class="text-muted">بدون برچسب</span></template>
            </USelectMenu>
          </UFormField>
        </div>
        <div v-if="trackingState === 'error'" class="mt-3 flex flex-wrap items-center gap-2 text-xs text-error" role="alert"><span>{{ trackingError }}</span><UButton color="error" variant="ghost" size="xs" type="button" :disabled="creating" @click="loadTrackingValues(form.project_id)">تلاش مجدد</UButton></div>
        <p v-else-if="trackingState === 'ready' && !categories.length && !tags.length" class="mt-3 text-xs text-muted">دسته‌بندی یا برچسب فعالی برای این پروژه تعریف نشده است.</p>
      </section>

      <USeparator />

      <section class="report-bug-form__section" aria-labelledby="report-problem-heading">
        <div class="report-bug-form__section-heading"><h2 id="report-problem-heading">شرح مشکل</h2><p>عنوان و توضیحات، حداقل اطلاعات لازم برای ثبت گزارش هستند.</p></div>
        <div class="report-bug-form__grid">
          <UFormField name="title" label="عنوان" required :error="fieldError('title')" class="report-bug-form__full" :ui="{ container: 'w-full' }"><UInput id="report-title" v-model="form.title" :disabled="creating || !!createdBug" maxlength="200" placeholder="خلاصه‌ای روشن از مشکل" class="w-full" @update:model-value="clearFieldError('title')" /><p v-if="titleCountVisible" class="mt-1 text-xs text-muted"><bdi dir="ltr">{{ form.title.length }}/200</bdi></p></UFormField>
          <UFormField name="description" label="توضیحات" required :error="fieldError('description')" class="report-bug-form__full" :ui="{ container: 'w-full' }"><UTextarea id="report-description" v-model="form.description" :disabled="creating || !!createdBug" :rows="5" autoresize placeholder="مشکل، اثر آن و زمینهٔ مشاهده‌شده را توضیح دهید." class="w-full" @update:model-value="clearFieldError('description')" /></UFormField>
        </div>
      </section>

      <USeparator />

      <section class="report-bug-form__section" aria-labelledby="report-reproduction-heading">
        <div class="report-bug-form__section-heading"><h2 id="report-reproduction-heading">بازتولید</h2><p>این اطلاعات اختیاری‌اند، اما بررسی مشکل را سریع‌تر می‌کنند.</p></div>
        <div class="report-bug-form__grid">
          <UFormField name="steps_to_reproduce" label="مراحل بازتولید" :error="fieldError('steps_to_reproduce')" class="report-bug-form__full" :ui="{ container: 'w-full' }"><UTextarea id="report-steps_to_reproduce" v-model="form.steps_to_reproduce" :disabled="creating || !!createdBug" :rows="4" autoresize placeholder="مراحل را به همان ترتیبی که انجام داده‌اید بنویسید." class="w-full" @update:model-value="clearFieldError('steps_to_reproduce')" /></UFormField>
          <UFormField name="expected_result" label="نتیجهٔ مورد انتظار" :error="fieldError('expected_result')" class="report-bug-form__half" :ui="{ container: 'w-full' }"><UTextarea id="report-expected_result" v-model="form.expected_result" :disabled="creating || !!createdBug" :rows="3" autoresize class="w-full" @update:model-value="clearFieldError('expected_result')" /></UFormField>
          <UFormField name="actual_result" label="نتیجهٔ فعلی" :error="fieldError('actual_result')" class="report-bug-form__half" :ui="{ container: 'w-full' }"><UTextarea id="report-actual_result" v-model="form.actual_result" :disabled="creating || !!createdBug" :rows="3" autoresize class="w-full" @update:model-value="clearFieldError('actual_result')" /></UFormField>
        </div>
      </section>

      <USeparator />

      <section class="report-bug-form__section" aria-labelledby="report-evidence-heading">
        <div class="report-bug-form__section-heading"><h2 id="report-evidence-heading">محیط و مدرک</h2><p>جزئیات فنی و فایل‌های پشتیبان را در صورت نیاز اضافه کنید.</p></div>
        <div class="report-bug-form__grid">
          <UFormField name="environment" label="محیط" :error="fieldError('environment')" class="report-bug-form__environment" :ui="{ container: 'w-full' }"><UTextarea id="report-environment" v-model="form.environment" :disabled="creating || !!createdBug" :rows="3" autoresize placeholder="مرورگر، دستگاه، تنظیمات یا زمینهٔ اجرا" class="w-full" @update:model-value="clearFieldError('environment')" /></UFormField>
          <UFormField name="platform" label="سکو" :error="fieldError('platform')" class="report-bug-form__quarter" :ui="{ container: 'w-full' }"><UInput id="report-platform" v-model="form.platform" :disabled="creating || !!createdBug" class="w-full" @update:model-value="clearFieldError('platform')" /></UFormField>
          <UFormField name="application_version" label="نسخهٔ برنامه" :error="fieldError('application_version')" class="report-bug-form__quarter" :ui="{ container: 'w-full' }"><UInput id="report-application_version" v-model="form.application_version" dir="auto" :disabled="creating || !!createdBug" class="w-full" @update:model-value="clearFieldError('application_version')" /></UFormField>
          <div class="report-bug-form__full"><AttachmentQueue :items="attachments" :can-add="!createdBug" :busy="creating || attachments.some((item) => item.state === 'uploading')" :selection-error="attachmentSelectionError" :max-bytes="MAX_ATTACHMENT_BYTES" @add="addAttachments" @remove="removeAttachment" @retry="retryAttachment" @clear-selection-error="attachmentSelectionError = ''" /></div>
        </div>
      </section>

      <div class="report-bug-form__actions"><div class="flex flex-wrap items-center gap-2"><UButton type="submit" :loading="creating" :disabled="creating || !!createdBug">{{ creating ? 'در حال ثبت باگ…' : 'ثبت باگ' }}</UButton><UButton to="/bugs" color="neutral" variant="ghost" :disabled="creating">بازگشت به فهرست باگ‌ها</UButton></div><p class="text-xs text-muted">پروژه، عنوان و توضیحات الزامی‌اند.</p></div>
    </form>
  </main>
</template>

<style scoped>
.report-bug-page { margin-inline: auto; max-width: 72rem; padding: 1.5rem; }
.report-bug-page__skeleton { display: grid; gap: 0.75rem; }
.report-bug-page__state { max-width: 42rem; }
.report-bug-form { display: grid; gap: 1.5rem; }
.report-bug-form__section { display: grid; gap: 1rem; }
.report-bug-form__section-heading h2 { color: var(--ui-text-highlighted); font-size: 0.9375rem; font-weight: 650; line-height: 1.5; margin: 0; }
.report-bug-form__section-heading p { color: var(--ui-text-muted); font-size: 0.75rem; line-height: 1.6; margin: 0.25rem 0 0; }
.report-bug-form__grid { display: grid; gap: 1rem; grid-template-columns: repeat(12, minmax(0, 1fr)); }
.report-bug-form__project { grid-column: span 5 / span 5; }
.report-bug-form__category { grid-column: span 3 / span 3; }
.report-bug-form__tags { grid-column: span 4 / span 4; }
.report-bug-form__full { grid-column: 1 / -1; }
.report-bug-form__half { grid-column: span 6 / span 6; }
.report-bug-form__environment { grid-column: span 6 / span 6; }
.report-bug-form__quarter { grid-column: span 3 / span 3; }
.report-bug-form__actions { align-items: center; border-top: 1px solid var(--ui-border); display: flex; gap: 0.75rem; justify-content: space-between; padding-top: 1rem; }
@media (max-width: 80rem) { .report-bug-form__project, .report-bug-form__category { grid-column: span 6 / span 6; } .report-bug-form__tags, .report-bug-form__environment { grid-column: 1 / -1; } .report-bug-form__quarter { grid-column: span 6 / span 6; } }
@media (max-width: 48rem) { .report-bug-page { padding: 1rem; } .report-bug-form__project, .report-bug-form__category, .report-bug-form__tags, .report-bug-form__half, .report-bug-form__environment, .report-bug-form__quarter { grid-column: 1 / -1; } .report-bug-form__actions { align-items: stretch; flex-direction: column; } .report-bug-form__actions :deep(button), .report-bug-form__actions :deep(a) { justify-content: center; } }
</style>
