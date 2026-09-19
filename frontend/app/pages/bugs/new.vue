<script setup lang="ts">
import { ApiError } from '~/plugins/api.client'
import { bugErrorMessage, emptyBugForm, useBugs, type BugFormData, type BugTrackingValue } from '~/composables/useBugs'

definePageMeta({ middleware: 'auth' })
const bugs = useBugs()
const workspace = useWorkspace()
const router = useRouter()
const initialValue = reactive(emptyBugForm())
const trackingValues = ref<BugTrackingValue[]>([])
const pending = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

async function loadTracking(projectId: string) {
  trackingValues.value = []
  const project = workspace.projects.value.find((item) => item.id === projectId)
  if (!project) return
  try { trackingValues.value = (await bugs.trackingValues(project.key)).data } catch (caught) { error.value = bugErrorMessage(caught) }
}

watch(() => workspace.selectedProject.value, (project) => {
  if (!initialValue.project_id && project) {
    initialValue.project_id = project.id
    void loadTracking(project.id)
  }
}, { immediate: true })

async function submit(value: BugFormData) {
  if (pending.value) return
  pending.value = true; error.value = ''; fieldErrors.value = {}
  try {
    const created = await bugs.create(value)
    await router.push(`/bugs/${created.data.public_id}`)
  } catch (caught) {
    if (caught instanceof ApiError) fieldErrors.value = caught.fieldErrors
    if (!(caught instanceof ApiError) || caught.status !== 422) error.value = bugErrorMessage(caught)
  } finally { pending.value = false }
}
</script>

<template>
  <main class="bug-page">
    <header class="bug-page-header"><div><p class="eyebrow">گزارش‌دهی</p><h1>ثبت باگ</h1><p>مشکل را شفاف توضیح دهید. دسترسی به پروژه و مجوز گزارش در سرور بررسی می‌شود.</p></div></header>
    <section class="bug-panel">
      <BugForm :initial-value="initialValue" :projects="workspace.projects.value" :tracking-values="trackingValues" :field-errors="fieldErrors" :error="error" :pending="pending" @project-change="loadTracking" @submit="submit" />
    </section>
  </main>
</template>
