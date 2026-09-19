<script setup lang="ts">
import { bugErrorMessage, useBugs, type BugReport } from '~/composables/useBugs'
import { formatDateTime, formatNumber, statusLabel } from '~/utils/presentation'

definePageMeta({ middleware: 'auth' })

const bugsApi = useBugs()
const assignedBugs = ref<BugReport[]>([])
const loading = ref(true)
const error = ref('')

async function load() {
  if (loading.value && assignedBugs.value.length) return

  loading.value = true
  error.value = ''
  try {
    assignedBugs.value = (await bugsApi.assignedToMe()).data
  } catch (caught) {
    error.value = bugErrorMessage(caught)
  } finally {
    loading.value = false
  }
}

await load()
</script>

<template>
  <main class="admin-page work-page">
    <header class="admin-header">
      <div>
        <p class="eyebrow">فضای کاری توسعه‌دهنده</p>
        <h1>کارهای تخصیص‌یافته</h1>
        <p>باگ‌های فعلی تخصیص‌یافته به شما در پروژه‌هایی که نقش توسعه‌دهنده شما فعال است.</p>
      </div>
      <span v-if="!loading && !error" class="work-count">{{ formatNumber(assignedBugs.length) }} مورد</span>
    </header>

    <section class="admin-panel admin-panel--wide">
      <div class="panel-title">
        <div>
          <h2>باگ‌های من</h2>
          <p class="panel-copy">مرتب‌شده بر اساس آخرین به‌روزرسانی سرور.</p>
        </div>
        <button class="admin-link button-reset" :disabled="loading" @click="load">
          {{ loading ? 'در حال به‌روزرسانی…' : 'به‌روزرسانی' }}
        </button>
      </div>

      <p v-if="loading" class="panel-copy" role="status">در حال بارگیری کارهای تخصیص‌یافته…</p>
      <div v-else-if="error" class="form-error" role="alert">
        {{ error }}
      </div>
      <div v-else-if="!assignedBugs.length" class="work-empty">
        <h3>کار تخصیص‌یافته‌ای وجود ندارد</h3>
        <p>هیچ باگی به عضویت‌های فعال توسعه‌دهنده شما تخصیص نیافته است.</p>
      </div>
      <div v-else class="admin-table-wrap">
        <table class="admin-table work-table">
          <thead>
            <tr>
              <th>باگ</th>
              <th>پروژه</th>
              <th>وضعیت</th>
              <th>اطلاعات کار</th>
              <th>آخرین به‌روزرسانی</th>
              <th><span class="sr-only">باز کردن</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="bug in assignedBugs" :key="bug.id">
              <td>
                <NuxtLink class="work-bug-link" dir="ltr" :to="`/bugs/${encodeURIComponent(bug.public_id)}`">{{ bug.public_id }}</NuxtLink>
                <small>{{ bug.title }}</small>
              </td>
              <td>
                {{ bug.project.name }}
                <small dir="ltr">{{ bug.project.key }}</small>
              </td>
              <td><span :class="['bug-status', `bug-status--${bug.status}`]">{{ statusLabel(bug.status) }}</span></td>
              <td>
                {{ bug.assignee?.display_name || 'بدون مسئول' }}
                <small>اولویت: {{ bug.priority?.name || 'تعیین‌نشده' }} · شدت: {{ bug.severity?.name || 'تعیین‌نشده' }}</small>
              </td>
              <td>{{ formatDateTime(bug.updated_at) }}</td>
              <td><NuxtLink class="admin-link" :to="`/bugs/${encodeURIComponent(bug.public_id)}`">باز کردن</NuxtLink></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</template>
