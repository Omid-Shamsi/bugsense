<script setup lang="ts">
import { formatNumber } from '~/utils/presentation'

interface PaginationMetaLike {
  current_page: number
  per_page: number
  total: number
  last_page: number
  from?: number | null
  to?: number | null
}

const props = withDefaults(defineProps<{
  meta: PaginationMetaLike | null
  pending?: boolean
  pageSizeOptions?: readonly number[]
  itemLabel?: string
  compact?: boolean
}>(), {
  pending: false,
  pageSizeOptions: () => [25, 50, 100],
  itemLabel: 'باگ',
  compact: false,
})

const emit = defineEmits<{
  'update:page': [page: number]
  'update:pageSize': [pageSize: number]
}>()

const from = computed(() => props.meta?.from ?? (props.meta && props.meta.total > 0 ? (props.meta.current_page - 1) * props.meta.per_page + 1 : null))
const to = computed(() => props.meta?.to ?? (props.meta ? Math.min(props.meta.current_page * props.meta.per_page, props.meta.total) : null))
const hasRange = computed(() => !!props.meta && props.meta.total > 0 && from.value != null && to.value != null)
const rangeFromTo = computed(() => hasRange.value ? `${formatNumber(from.value as number)}–${formatNumber(to.value as number)}` : '')
const totalText = computed(() => formatNumber(props.meta?.total ?? 0))

const pageSizeItems = computed(() => props.pageSizeOptions.map((value) => ({ label: String(value), value })))

function onPageChange(page: number) {
  if (!props.meta || props.pending || page < 1 || page > props.meta.last_page || page === props.meta.current_page) return
  emit('update:page', page)
}

function onPageSizeChange(value: number | null) {
  if (value == null || !props.meta || value === props.meta.per_page) return
  emit('update:pageSize', value)
}
</script>

<template>
  <div class="server-pagination-bar" role="navigation" aria-label="صفحه‌بندی نتایج">
    <span class="server-pagination-bar__range" role="status">
      <template v-if="hasRange"><bdi dir="ltr">{{ rangeFromTo }}</bdi> از <bdi dir="ltr">{{ totalText }}</bdi> {{ itemLabel }}</template>
      <template v-else>{{ totalText }} {{ itemLabel }}</template>
    </span>

    <div v-if="meta && meta.last_page > 1" class="server-pagination-bar__nav">
      <UPagination
        :page="meta.current_page"
        :items-per-page="meta.per_page"
        :total="meta.total"
        :disabled="pending"
        :sibling-count="compact ? 0 : 1"
        size="sm"
        @update:page="onPageChange"
      />
    </div>

    <label v-if="pageSizeOptions.length && meta" class="server-pagination-bar__size">
      <span id="pagination-page-size-label" class="sr-only">تعداد در صفحه</span>
      <USelectMenu
        :model-value="meta.per_page"
        aria-labelledby="pagination-page-size-label"
        :items="pageSizeItems"
        value-key="value"
        :disabled="pending"
        :search-input="false"
        class="server-pagination-bar__size-select"
        @update:model-value="onPageSizeChange"
      />
    </label>
  </div>
</template>

<style scoped>
.server-pagination-bar { align-items: center; display: flex; flex-wrap: wrap; gap: .75rem; justify-content: space-between; }
.server-pagination-bar__range { color: var(--ui-text-muted); font-size: .8rem; white-space: nowrap; }
.server-pagination-bar__nav { display: flex; }
.server-pagination-bar__size-select { min-width: 5rem; }
</style>
