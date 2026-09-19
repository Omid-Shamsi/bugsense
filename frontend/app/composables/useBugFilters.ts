import type { BugReport } from './useBugs'

export type BugStatusFilter = 'submitted' | 'review' | 'assigned' | 'in_progress' | 'resolved' | 'qa_verification' | 'closed' | 'needs_information' | 'reopened'
export type BugSort = 'created_at' | 'updated_at' | 'priority' | 'severity' | 'public_id'
export type SortDirection = 'asc' | 'desc'
export type FilterMode = 'list' | 'dashboard'

export interface BugFilterState {
  q: string
  project: string[]
  status: BugStatusFilter[]
  severity: string[]
  priority: string[]
  category: string[]
  reporter: string[]
  assignee: string[]
  tag: string[]
  created_from: string
  created_to: string
  updated_from: string
  updated_to: string
  sort: BugSort
  direction: SortDirection
  page: number
  per_page: number
}

export interface PaginationMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
  from?: number | null
  to?: number | null
}

export interface BugSearchResponse {
  data: BugReport[]
  meta: PaginationMeta
  links: Record<string, string | null>
}

export interface DashboardCounts {
  total: number
  open: number
  awaiting_review: number
  awaiting_qa: number
  resolved: number
  qa_verification: number
  in_progress: number
  reopened: number
  closed: number
  assigned_to_current_developer: number
}

export interface TrackingBreakdown { id: string | null; code: string | null; name: string; count: number }
export interface ProjectBreakdown { id: string; key: string; name: string; count: number }
export interface DeveloperBreakdown { id: string | null; display_name: string; count: number }

export interface DashboardData {
  context: { filters: Record<string, unknown>; generated_at: string }
  counts: DashboardCounts
  breakdowns: {
    severity: TrackingBreakdown[]
    category: TrackingBreakdown[]
    project: ProjectBreakdown[]
    developer: DeveloperBreakdown[]
  }
  average_resolution_time: {
    sample_count: number
    milliseconds: number | null
    included_outcomes: string[]
  }
}

export type BugListState = 'loading' | 'empty' | 'error' | 'ready'

const arrayKeys = ['project', 'status', 'severity', 'priority', 'category', 'reporter', 'assignee', 'tag'] as const
const dateKeys = ['created_from', 'created_to', 'updated_from', 'updated_to'] as const
const sorts: BugSort[] = ['created_at', 'updated_at', 'priority', 'severity', 'public_id']

export function createBugFilterState(): BugFilterState {
  return {
    q: '',
    project: [],
    status: [],
    severity: [],
    priority: [],
    category: [],
    reporter: [],
    assignee: [],
    tag: [],
    created_from: '',
    created_to: '',
    updated_from: '',
    updated_to: '',
    sort: 'updated_at',
    direction: 'desc',
    page: 1,
    per_page: 25,
  }
}

export function cloneBugFilterState(filters: BugFilterState): BugFilterState {
  return {
    ...filters,
    project: [...filters.project],
    status: [...filters.status],
    severity: [...filters.severity],
    priority: [...filters.priority],
    category: [...filters.category],
    reporter: [...filters.reporter],
    assignee: [...filters.assignee],
    tag: [...filters.tag],
  }
}

export function serializeBugFilters(filters: BugFilterState, mode: FilterMode): string {
  const params = new URLSearchParams()
  const q = filters.q.trim()
  if (q) params.set('q', q)

  for (const key of arrayKeys) {
    const values = [...new Set(filters[key].map((value) => value.trim()).filter(Boolean))]
    for (const value of values) params.append(`${key}[]`, value)
  }

  for (const key of dateKeys) {
    const value = filters[key].trim()
    if (value) params.set(key, value)
  }

  if (mode === 'list') {
    if (filters.sort !== 'updated_at' || filters.direction !== 'desc') {
      params.set('sort', filters.sort)
      params.set('direction', filters.direction)
    }
    if (filters.page > 1) params.set('page', String(filters.page))
    if (filters.per_page !== 25) params.set('per_page', String(filters.per_page))
  }

  return params.toString()
}

function firstString(value: unknown): string {
  if (Array.isArray(value)) return typeof value[0] === 'string' ? value[0] : ''
  return typeof value === 'string' ? value : ''
}

function allStrings(query: Record<string, unknown>, key: string): string[] {
  const value = query[key] ?? query[`${key}[]`]
  if (Array.isArray(value)) return value.filter((item): item is string => typeof item === 'string' && item !== '')
  return typeof value === 'string' && value !== '' ? [value] : []
}

function positiveInteger(value: unknown, fallback: number): number {
  const parsed = Number(firstString(value))
  return Number.isInteger(parsed) && parsed > 0 ? parsed : fallback
}

export function filtersFromQuery(query: Record<string, unknown>): BugFilterState {
  const filters = createBugFilterState()
  filters.q = firstString(query.q)
  filters.project = allStrings(query, 'project')
  filters.status = allStrings(query, 'status') as BugStatusFilter[]
  filters.severity = allStrings(query, 'severity')
  filters.priority = allStrings(query, 'priority')
  filters.category = allStrings(query, 'category')
  filters.reporter = allStrings(query, 'reporter')
  filters.assignee = allStrings(query, 'assignee')
  filters.tag = allStrings(query, 'tag')
  for (const key of dateKeys) filters[key] = firstString(query[key])

  const sort = firstString(query.sort)
  if (sorts.includes(sort as BugSort)) filters.sort = sort as BugSort
  filters.direction = firstString(query.direction) === 'asc' ? 'asc' : 'desc'
  filters.page = positiveInteger(query.page, 1)
  filters.per_page = Math.min(positiveInteger(query.per_page, 25), 100)
  return filters
}

export function filtersToRouteQuery(filters: BugFilterState, mode: FilterMode): Record<string, string | string[]> {
  const params = new URLSearchParams(serializeBugFilters(filters, mode))
  const query: Record<string, string | string[]> = {}

  for (const key of arrayKeys) {
    const values = params.getAll(`${key}[]`)
    if (values.length) query[key] = values
  }
  for (const key of ['q', ...dateKeys, 'sort', 'direction', 'page', 'per_page']) {
    const value = params.get(key)
    if (value !== null) query[key] = value
  }
  return query
}

export function getBugListState(loading: boolean, error: string, resultCount: number): BugListState {
  if (loading) return 'loading'
  if (error) return 'error'
  return resultCount === 0 ? 'empty' : 'ready'
}

export function discoveryErrorMessage(error: unknown): string {
  const apiError = error as { status?: number; kind?: string; fieldErrors?: Record<string, string[]>; problem?: { detail?: string; errors?: Record<string, string[]> } }
  if (apiError?.status === 401) return 'نشست شما پایان یافته است. برای ادامه دوباره وارد شوید.'
  if (apiError?.status === 403) return 'دسترسی به این نمای گزارش مجاز نیست.'
  if (apiError?.status === 404) return 'داده‌های گزارش درخواست‌شده در دسترس نیست.'
  if (apiError?.status === 419) return 'مهلت بررسی امنیتی پایان یافته است. درخواست را دوباره ارسال کنید.'
  if (apiError?.status === 422) {
    const errors = apiError.fieldErrors || apiError.problem?.errors || {}
    const firstError = Object.values(errors).flat().find(Boolean)
    return firstError || apiError.problem?.detail || 'یک یا چند فیلتر نامعتبر است.'
  }
  if (apiError?.kind === 'network') return 'ارتباط با BugSense برقرار نشد. اتصال را بررسی و دوباره تلاش کنید.'
  return apiError?.problem?.detail || 'سرور نتوانست این گزارش را بارگیری کند.'
}

export function useBugDiscovery() {
  const api = useApi()
  const endpoint = (path: string, query: string) => query ? `${path}?${query}` : path

  return {
    list: (filters: BugFilterState) => api.request<BugSearchResponse>(endpoint('/bugs', serializeBugFilters(filters, 'list'))),
    dashboard: (filters: BugFilterState) => api.request<{ data: DashboardData }>(endpoint('/dashboards/summary', serializeBugFilters(filters, 'dashboard'))),
  }
}
