<script setup lang="ts">
import { bugErrorMessage, useBugs, type BugReport } from '~/composables/useBugs'
import { formatNumber, resolutionLabel, statusLabel } from '~/utils/presentation'

definePageMeta({ middleware: 'auth' })

const bugsApi = useBugs()
const queuedBugs = ref<BugReport[]>([])
const loading = ref(true)
const error = ref('')

async function load() {
  if (loading.value && queuedBugs.value.length) return

  loading.value = true
  error.value = ''
  try {
    queuedBugs.value = (await bugsApi.qaQueue()).data
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
        <p class="eyebrow">فضای کاری QA</p>
        <h1>صف بررسی</h1>
        <p>نتیجه‌های رفعی که هم‌اکنون به‌عنوان QA مستقل برای شما قابل بررسی است.</p>
      </div>
      <span v-if="!loading && !error" class="work-count">{{ formatNumber(queuedBugs.length) }} مورد در انتظار QA</span>
    </header>

    <section class="admin-panel admin-panel--wide">
      <div class="panel-title">
        <div>
          <h2>آماده بررسی</h2>
          <p class="panel-copy">مرتب‌شده بر اساس آخرین به‌روزرسانی سرور.</p>
        </div>
        <button class="admin-link button-reset" :disabled="loading" @click="load">
          {{ loading ? 'در حال به‌روزرسانی…' : 'به‌روزرسانی' }}
        </button>
      </div>

      <p v-if="loading" class="panel-copy" role="status">در حال بارگیری کارهای QA…</p>
      <div v-else-if="error" class="form-error" role="alert">{{ error }}</div>
      <div v-else-if="!queuedBugs.length" class="work-empty">
        <h3>موردی برای بررسی وجود ندارد</h3>
        <p>در حال حاضر هیچ نتیجه رفعی برای حساب QA شما واجد شرایط نیست.</p>
      </div>
      <div v-else class="admin-table-wrap">
        <table class="admin-table work-table qa-table">
          <thead>
            <tr>
              <th>باگ</th>
              <th>پروژه</th>
              <th>وضعیت</th>
              <th>نتیجه رفع</th>
              <th>رفع‌کننده</th>
              <th>توضیحات</th>
              <th><span class="sr-only">باز کردن</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="bug in queuedBugs" :key="bug.id">
              <td>
                <NuxtLink class="work-bug-link" dir="ltr" :to="`/bugs/${encodeURIComponent(bug.public_id)}`">{{ bug.public_id }}</NuxtLink>
                <small>{{ bug.title }}</small>
              </td>
              <td>
                {{ bug.project.name }}
                <small dir="ltr">{{ bug.project.key }}</small>
              </td>
              <td><span :class="['bug-status', `bug-status--${bug.status}`]">{{ statusLabel(bug.status) }}</span></td>
              <td>{{ bug.active_resolution ? resolutionLabel(bug.active_resolution.outcome) : 'در دسترس نیست' }}</td>
              <td>{{ bug.active_resolution?.recorded_by.display_name || 'در دسترس نیست' }}</td>
              <td class="qa-context">
                {{ bug.active_resolution?.explanation || 'توضیحی برای نتیجه رفع ارائه نشده است.' }}
                <small v-if="bug.active_resolution?.qa_instructions">QA: {{ bug.active_resolution.qa_instructions }}</small>
              </td>
              <td><NuxtLink class="admin-link" :to="`/bugs/${encodeURIComponent(bug.public_id)}`">باز کردن</NuxtLink></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</template>
