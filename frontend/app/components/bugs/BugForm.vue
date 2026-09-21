<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { BugFormData, BugProject, BugTrackingValue } from '~/composables/useBugs'
import { actionLabel } from '~/utils/presentation'

const props = withDefaults(defineProps<{
  initialValue: BugFormData
  projects?: readonly BugProject[]
  trackingValues?: readonly BugTrackingValue[]
  fieldErrors?: Record<string, string[]>
  error?: string
  pending?: boolean
  mode?: 'create' | 'edit'
  allowedActions?: string[]
}>(), { projects: () => [], trackingValues: () => [], fieldErrors: () => ({}), error: '', pending: false, mode: 'create', allowedActions: () => [] })

const emit = defineEmits<{ submit: [value: BugFormData]; projectChange: [projectId: string] }>()
const form = reactive<BugFormData>({ ...props.initialValue, tag_ids: [...props.initialValue.tag_ids] })
const canEdit = computed(() => props.mode === 'create' || props.allowedActions.includes('edit'))
const categories = computed(() => props.trackingValues.filter((value) => value.kind === 'category' && (value.active || value.id === form.category_id)))
const tags = computed(() => props.trackingValues.filter((value) => value.kind === 'tag' && (value.active || form.tag_ids.includes(value.id))))

watch(() => props.initialValue, (value) => Object.assign(form, { ...value, tag_ids: [...value.tag_ids] }), { deep: true })

function projectChanged() {
  form.category_id = ''
  form.tag_ids = []
  emit('projectChange', form.project_id)
}

function submit() {
  if (!props.pending) emit('submit', { ...form, tag_ids: [...form.tag_ids] })
}
</script>

<template>
  <form v-if="canEdit" class="bug-form" novalidate @submit.prevent="submit">
    <div v-if="error" class="form-error" role="alert">{{ error }}</div>

    <div v-if="mode === 'create'" class="field">
      <label for="bug-project">پروژه</label>
      <select id="bug-project" v-model="form.project_id" :disabled="pending" required :aria-invalid="!!fieldErrors.project_id" aria-describedby="bug-project-error" @change="projectChanged">
        <option value="" disabled>پروژه را انتخاب کنید</option>
        <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }} · {{ project.key }}</option>
      </select>
      <p v-if="fieldErrors.project_id?.[0]" id="bug-project-error" class="field-error">{{ fieldErrors.project_id[0] }}</p>
    </div>

    <div class="field">
      <label for="bug-title">عنوان</label>
      <input id="bug-title" v-model="form.title" :disabled="pending" maxlength="200" required :aria-invalid="!!fieldErrors.title" aria-describedby="bug-title-error">
      <p v-if="fieldErrors.title?.[0]" id="bug-title-error" class="field-error">{{ fieldErrors.title[0] }}</p>
    </div>

    <div class="field">
      <label for="bug-description">توضیحات</label>
      <textarea id="bug-description" v-model="form.description" :disabled="pending" rows="6" required :aria-invalid="!!fieldErrors.description" aria-describedby="bug-description-error" />
      <p v-if="fieldErrors.description?.[0]" id="bug-description-error" class="field-error">{{ fieldErrors.description[0] }}</p>
    </div>

    <div class="bug-form-grid">
      <div class="field"><label for="bug-steps">مراحل بازتولید</label><textarea id="bug-steps" v-model="form.steps_to_reproduce" :disabled="pending" rows="5" /><p v-if="fieldErrors.steps_to_reproduce?.[0]" class="field-error">{{ fieldErrors.steps_to_reproduce[0] }}</p></div>
      <div class="field"><label for="bug-environment">محیط</label><textarea id="bug-environment" v-model="form.environment" :disabled="pending" rows="5" /><p v-if="fieldErrors.environment?.[0]" class="field-error">{{ fieldErrors.environment[0] }}</p></div>
      <div class="field"><label for="bug-expected">نتیجه مورد انتظار</label><textarea id="bug-expected" v-model="form.expected_result" :disabled="pending" rows="4" /><p v-if="fieldErrors.expected_result?.[0]" class="field-error">{{ fieldErrors.expected_result[0] }}</p></div>
      <div class="field"><label for="bug-actual">نتیجه فعلی</label><textarea id="bug-actual" v-model="form.actual_result" :disabled="pending" rows="4" /><p v-if="fieldErrors.actual_result?.[0]" class="field-error">{{ fieldErrors.actual_result[0] }}</p></div>
      <div class="field"><label for="bug-platform">سکو</label><input id="bug-platform" v-model="form.platform" :disabled="pending"><p v-if="fieldErrors.platform?.[0]" class="field-error">{{ fieldErrors.platform[0] }}</p></div>
      <div class="field"><label for="bug-version">نسخه برنامه</label><input id="bug-version" v-model="form.application_version" dir="ltr" :disabled="pending"><p v-if="fieldErrors.application_version?.[0]" class="field-error">{{ fieldErrors.application_version[0] }}</p></div>
    </div>

    <div class="field"><label for="bug-category">دسته‌بندی</label><select id="bug-category" v-model="form.category_id" :disabled="pending"><option value="">تعیین‌نشده</option><option v-for="category in categories" :key="category.id" :value="category.id" :disabled="!category.active">{{ category.name }}{{ category.active ? '' : ' (غیرفعال)' }}</option></select><p v-if="fieldErrors.category_id?.[0]" class="field-error">{{ fieldErrors.category_id[0] }}</p></div>

    <fieldset class="tag-field"><legend>برچسب‌ها</legend><p v-if="!tags.length" class="muted">برچسب فعالی تنظیم نشده است.</p><div v-else class="tag-options"><label v-for="tag in tags" :key="tag.id" class="check-row"><input v-model="form.tag_ids" type="checkbox" :value="tag.id" :disabled="pending || !tag.active">{{ tag.name }}<span v-if="!tag.active" class="muted">(غیرفعال)</span></label></div><p v-if="fieldErrors.tag_ids?.[0] || fieldErrors['tag_ids.0']?.[0]" class="field-error">{{ fieldErrors.tag_ids?.[0] || fieldErrors['tag_ids.0']?.[0] }}</p></fieldset>

    <button class="button button-primary bug-submit" type="submit" :disabled="pending">{{ pending ? 'در حال ذخیره…' : mode === 'create' ? actionLabel('create_bug') : actionLabel('save_changes') }}</button>
    <div v-if="$slots.attachments" class="bug-form-evidence">
      <slot name="attachments" />
    </div>
  </form>
  <p v-else class="readonly-note">این گزارش با دسترسی فعلی شما فقط قابل مشاهده است.</p>
</template>
