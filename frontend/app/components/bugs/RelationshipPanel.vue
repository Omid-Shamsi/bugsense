<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ApiError } from '~/plugins/api.client'
import { bugErrorMessage, useBugs, type BugRelationship, type BugRelationshipType, type BugReport } from '~/composables/useBugs'
import { actionLabel, formatDateTime, formatNumber, relationshipLabel } from '~/utils/presentation'

const props = defineProps<{ bug: BugReport }>()
const emit = defineEmits<{ changed: [] }>()
const bugs = useBugs()
const session = useSession()
const relationships = ref<BugRelationship[]>([])
const loading = ref(false)
const pending = ref('')
const error = ref('')
const success = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const type = ref<BugRelationshipType>('related_to')
const targetBugId = ref('')
let requestVersion = 0

const relationshipTypes: { value: BugRelationshipType; label: string }[] = [
  { value: 'duplicate_of', label: 'تکراریِ' },
  { value: 'related_to', label: 'مرتبط با' },
  { value: 'blocks', label: 'مسدودکننده' },
]

// This is only a presentation hint. Laravel re-checks the live grant for
// every create/deactivate request and remains authoritative.
const canManage = computed(() => {
  const user = session.user.value
  return !!user && (user.is_system_admin || user.memberships.some((membership) => (
    membership.active
    && membership.project.id === props.bug.project.id
    && membership.roles.includes('admin')
  )))
})

function relatedBugId(relationship: BugRelationship): string {
  return relationship.source_bug_id.toUpperCase() === props.bug.public_id.toUpperCase()
    ? relationship.target_bug_id
    : relationship.source_bug_id
}

function relationshipErrorMessage(caught: unknown): string {
  if (caught instanceof ApiError && caught.status === 404) return 'این باگ، ارتباط یا باگ مقصد در دسترس نیست.'
  if (caught instanceof ApiError && caught.status === 422 && Object.keys(caught.fieldErrors).length) return 'جزئیات ارتباط زیر را بررسی کنید.'
  return bugErrorMessage(caught)
}

async function load() {
  const version = ++requestVersion
  loading.value = true
  error.value = ''
  try {
    const response = await bugs.relationships(props.bug.public_id)
    if (version === requestVersion) relationships.value = response.data
  } catch (caught) {
    if (version === requestVersion) error.value = relationshipErrorMessage(caught)
  } finally {
    if (version === requestVersion) loading.value = false
  }
}

async function create() {
  if (pending.value || !targetBugId.value.trim()) return
  pending.value = 'create'
  error.value = ''
  success.value = ''
  fieldErrors.value = {}

  try {
    await bugs.createRelationship(props.bug.public_id, type.value, targetBugId.value.trim())
    targetBugId.value = ''
    await load()
    if (!error.value) {
      success.value = 'ارتباط ایجاد شد.'
      emit('changed')
    }
  } catch (caught) {
    if (caught instanceof ApiError) fieldErrors.value = caught.fieldErrors
    error.value = relationshipErrorMessage(caught)
  } finally {
    pending.value = ''
  }
}

async function deactivate(relationship: BugRelationship) {
  if (pending.value) return
  pending.value = relationship.id
  error.value = ''
  success.value = ''
  fieldErrors.value = {}

  try {
    await bugs.deactivateRelationship(relationship.id)
    await load()
    if (!error.value) {
      success.value = 'ارتباط غیرفعال شد.'
      emit('changed')
    }
  } catch (caught) {
    error.value = relationshipErrorMessage(caught)
  } finally {
    pending.value = ''
  }
}

watch(() => props.bug.public_id, () => {
  relationships.value = []
  success.value = ''
  fieldErrors.value = {}
  void load()
}, { immediate: true })
</script>

<template>
  <section class="bug-panel traceability-panel relationship-panel" :aria-busy="loading || !!pending">
    <div class="panel-title">
      <div>
        <p class="eyebrow">پیوندها</p>
        <h2>ارتباط‌ها</h2>
      </div>
      <span v-if="!loading" class="traceability-count">{{ formatNumber(relationships.length) }}</span>
    </div>

    <p v-if="error" class="form-error" role="alert">{{ error }}</p>
    <p v-if="success" class="admin-notice admin-notice--success" role="status">{{ success }}</p>

    <p v-if="loading && !relationships.length" class="panel-copy">در حال بارگیری ارتباط‌ها…</p>
    <p v-else-if="!relationships.length && !error" class="traceability-empty">ارتباط فعالی وجود ندارد.</p>

    <ul v-if="relationships.length" class="relationship-list">
      <li v-for="relationship in relationships" :key="relationship.id" class="relationship-item">
        <div class="relationship-heading">
          <div>
            <span class="relationship-label">{{ relationshipLabel(relationship.label) }}</span>
            <NuxtLink class="relationship-bug-link" dir="ltr" :to="`/bugs/${encodeURIComponent(relatedBugId(relationship))}`">{{ relatedBugId(relationship) }}</NuxtLink>
          </div>
          <span v-if="relationship.active" class="status-badge status-badge--active">فعال</span>
        </div>
        <p>
          ایجادشده در {{ formatDateTime(relationship.created_at) }}
          <template v-if="relationship.created_by?.display_name"> توسط {{ relationship.created_by.display_name }}</template>
        </p>
        <button v-if="canManage" class="text-button relationship-deactivate" type="button" :disabled="!!pending" @click="deactivate(relationship)">
          {{ pending === relationship.id ? 'در حال غیرفعال‌سازی…' : actionLabel('deactivate') }}
        </button>
      </li>
    </ul>

    <form v-if="canManage" class="workflow-form relationship-form" @submit.prevent="create">
      <div>
        <h3>ایجاد ارتباط</h3>
        <p>قابلیت مشاهده، مرز پروژه، یکتایی و حلقه‌های تکرار در سرور بررسی می‌شوند.</p>
      </div>
      <label for="relationship-type">نوع ارتباط</label>
      <select id="relationship-type" v-model="type" :disabled="!!pending" :aria-invalid="!!fieldErrors.type" aria-describedby="relationship-type-error">
        <option v-for="option in relationshipTypes" :key="option.value" :value="option.value">{{ option.label }}</option>
      </select>
      <p v-if="fieldErrors.type?.[0]" id="relationship-type-error" class="field-error">{{ fieldErrors.type[0] }}</p>

      <label for="relationship-target">شناسه عمومی باگ مقصد</label>
      <input id="relationship-target" v-model="targetBugId" dir="ltr" type="text" autocomplete="off" placeholder="BUG-123" required :disabled="!!pending" :aria-invalid="!!(fieldErrors.target_bug_id || fieldErrors.duplicate_bug_id)" aria-describedby="relationship-target-error">
      <p v-if="fieldErrors.target_bug_id?.[0] || fieldErrors.duplicate_bug_id?.[0]" id="relationship-target-error" class="field-error">{{ fieldErrors.target_bug_id?.[0] || fieldErrors.duplicate_bug_id?.[0] }}</p>
      <button class="button button-primary workflow-button" :disabled="!!pending || !targetBugId.trim()">
        {{ pending === 'create' ? 'در حال ایجاد…' : actionLabel('create_relationship') }}
      </button>
    </form>
  </section>
</template>
