<script setup lang="ts">
import { ref } from 'vue'
import type { AdminTrackingValue } from '~/composables/useAdministration'

const props = withDefaults(defineProps<{
  kind: 'assignment' | 'tracking'
  message: string
  pending?: boolean
  replacements?: AdminTrackingValue[]
}>(), { pending: false, replacements: () => [] })

const emit = defineEmits<{
  cancel: []
  unassign: []
  reassign: [userId: string]
  unset: []
  replace: [valueId: string]
}>()

const replacementId = ref('')
</script>

<template>
  <section class="remediation-panel" aria-live="polite">
    <h3>کارهای باز به اصلاح نیاز دارند</h3>
    <p>{{ message }}</p>

    <template v-if="kind === 'assignment'">
      <label>شناسه کاربر توسعه‌دهنده جایگزین
        <input v-model.trim="replacementId" dir="ltr" :disabled="pending" placeholder="UUID کاربر">
      </label>
      <div class="remediation-actions">
        <button class="button button-primary" type="button" :disabled="pending || !replacementId" @click="emit('reassign', replacementId)">تخصیص مجدد و غیرفعال‌سازی</button>
        <button class="button button-secondary" type="button" :disabled="pending" @click="emit('unassign')">لغو تخصیص و غیرفعال‌سازی</button>
      </div>
    </template>

    <template v-else>
      <label>مقدار جایگزین
        <select v-model="replacementId" :disabled="pending">
          <option value="">یک مقدار فعال از همان نوع انتخاب کنید</option>
          <option v-for="value in replacements" :key="value.id" :value="value.id">{{ value.name }}</option>
        </select>
      </label>
      <div class="remediation-actions">
        <button class="button button-primary" type="button" :disabled="pending || !replacementId" @click="emit('replace', replacementId)">جایگزینی و غیرفعال‌سازی</button>
        <button class="button button-secondary" type="button" :disabled="pending" @click="emit('unset')">حذف مقدار و غیرفعال‌سازی</button>
      </div>
    </template>

    <button class="text-button" type="button" :disabled="pending" @click="emit('cancel')">انصراف از اصلاح</button>
  </section>
</template>
