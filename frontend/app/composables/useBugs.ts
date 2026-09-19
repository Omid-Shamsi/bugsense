import { ApiError } from '~/plugins/api.client'

export interface BugProject { id: string; key: string; name: string; description: string | null; active: boolean }
export interface BugUserSummary { id: string; display_name: string; active: boolean }
export interface BugTrackingValue { id: string; project_id: string; kind: 'category' | 'priority' | 'severity' | 'tag' | 'resolution_label'; code: string; name: string; rank: number | null; active: boolean }
export interface ActivityEvent {
  sequence: number
  type: string
  actor: BugUserSummary | null
  occurred_at: string
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  reason_or_result: string | null
}
export interface ActivityPaginationMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}
export interface ActivityPage {
  data: ActivityEvent[]
  meta: ActivityPaginationMeta
  links: Record<string, string | null>
}
export type BugRelationshipType = 'duplicate_of' | 'related_to' | 'blocks'
export interface BugRelationship {
  id: string
  type: BugRelationshipType
  active: boolean
  source_bug_id: string
  target_bug_id: string
  label: string
  created_by: BugUserSummary
  created_at: string
  deactivated_at: string | null
}
export type AttachmentState = 'ready' | 'removed'
export interface BugAttachment {
  id: string
  bug_id: string
  uploaded_by: BugUserSummary | null
  original_name: string
  declared_content_type: string
  detected_content_type: string
  byte_size: number
  state: AttachmentState
  created_at: string
  removed_at: string | null
  download_url: string | null
}
export type ResolutionOutcome = 'fixed' | 'duplicate' | 'cannot_reproduce' | 'wont_fix'
export type QAVerificationDecision = 'approved' | 'rejected'
export interface QAVerificationResult {
  decision: QAVerificationDecision
  verification_notes: string
  verifier: BugUserSummary
  created_at: string
}
export interface ResolutionAttempt {
  id: string
  attempt_number: number
  outcome: ResolutionOutcome
  explanation: string
  qa_instructions: string | null
  recorded_by: BugUserSummary
  recorded_at: string
  qa_result: QAVerificationResult | null
}
export interface BugReport {
  id: string
  public_id: string
  project: BugProject
  reporter: BugUserSummary
  assignee: BugUserSummary | null
  title: string
  description: string
  steps_to_reproduce: string | null
  expected_result: string | null
  actual_result: string | null
  environment: string | null
  platform: string | null
  application_version: string | null
  status: string
  category: BugTrackingValue | null
  priority: BugTrackingValue | null
  severity: BugTrackingValue | null
  tags: BugTrackingValue[]
  active_resolution: ResolutionAttempt | null
  resolution_attempts: ResolutionAttempt[]
  created_at: string
  updated_at: string
  allowed_actions: string[]
}

export interface BugFormData {
  project_id: string
  title: string
  description: string
  steps_to_reproduce: string
  expected_result: string
  actual_result: string
  environment: string
  platform: string
  application_version: string
  category_id: string
  tag_ids: string[]
}

export type NonFixResolutionData =
  | { outcome: 'duplicate'; reason: string; duplicate_bug_id: string }
  | { outcome: 'cannot_reproduce'; reason: string; attempted_steps: string; environment: string }
  | { outcome: 'wont_fix'; reason: string; decision_rationale: string }

export function emptyBugForm(projectId = ''): BugFormData {
  return { project_id: projectId, title: '', description: '', steps_to_reproduce: '', expected_result: '', actual_result: '', environment: '', platform: '', application_version: '', category_id: '', tag_ids: [] }
}

export function bugToForm(bug: BugReport): BugFormData {
  return {
    project_id: bug.project.id,
    title: bug.title,
    description: bug.description,
    steps_to_reproduce: bug.steps_to_reproduce || '',
    expected_result: bug.expected_result || '',
    actual_result: bug.actual_result || '',
    environment: bug.environment || '',
    platform: bug.platform || '',
    application_version: bug.application_version || '',
    category_id: bug.category?.id || '',
    tag_ids: bug.tags.map((tag) => tag.id),
  }
}

export function bugErrorMessage(error: unknown): string {
  if (!(error instanceof ApiError)) return 'سرور نتوانست این درخواست را انجام دهد.'
  if (error.status === 403) return 'با دسترسی فعلی شما به پروژه، این عملیات مجاز نیست.'
  if (error.status === 404) return 'این باگ یا پروژه در دسترس نیست.'
  if (error.status === 409) return error.problem?.detail || 'وضعیت باگ تغییر کرده است. صفحه را به‌روز و دوباره تلاش کنید.'
  if (error.status === 419) return 'مهلت بررسی امنیتی پایان یافته است. دوباره تلاش کنید.'
  if (error.kind === 'network') return 'ارتباط با BugSense برقرار نشد. اتصال را بررسی و دوباره تلاش کنید.'
  return error.problem?.detail || 'سرور نتوانست این درخواست را انجام دهد.'
}

export function attachmentErrorMessage(error: unknown): string {
  if (!(error instanceof ApiError)) return 'سرور نتوانست درخواست پیوست را انجام دهد.'

  const fileError = error.fieldErrors.file?.[0]
  if (fileError) return fileError
  if (error.status === 401) return 'نشست شما پایان یافته است. وارد شوید و دوباره تلاش کنید.'
  if (error.status === 403) return error.problem?.detail || 'تغییر پیوست‌های این گزارش مجاز نیست.'
  if (error.status === 404) return error.problem?.detail || 'این پیوست یا گزارش دیگر در دسترس نیست.'
  if (error.status === 409) return error.problem?.detail || 'وضعیت پیوست تغییر کرده است. صفحه را به‌روز و دوباره تلاش کنید.'
  if (error.status === 413) return error.problem?.detail || 'فایل انتخاب‌شده بیش از حد بزرگ است.'
  if (error.status === 415) return error.problem?.detail || 'نوع فایل انتخاب‌شده پشتیبانی نمی‌شود.'
  if (error.status === 419) return 'مهلت بررسی امنیتی پایان یافته است. دوباره تلاش کنید.'
  if (error.status === 422) return error.problem?.detail || 'یک فایل معتبر و غیرخالی انتخاب کنید.'
  if (error.kind === 'network') return 'ارتباط با BugSense برقرار نشد. اتصال را بررسی و دوباره تلاش کنید.'
  return error.problem?.detail || 'سرور نتوانست درخواست پیوست را انجام دهد.'
}

export function useBugs() {
  const api = useApi()
  const payload = (data: BugFormData) => ({
    ...data,
    steps_to_reproduce: data.steps_to_reproduce || null,
    expected_result: data.expected_result || null,
    actual_result: data.actual_result || null,
    environment: data.environment || null,
    platform: data.platform || null,
    application_version: data.application_version || null,
    category_id: data.category_id || null,
  })
  return {
    create: (data: BugFormData) => api.request<{ data: BugReport }>('/bugs', { method: 'POST', body: payload(data) }),
    get: (publicId: string) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}`),
    update: (publicId: string, data: Record<string, unknown>) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}`, { method: 'PATCH', body: data }),
    trackingValues: (projectKey: string) => api.request<{ data: BugTrackingValue[] }>(`/projects/${encodeURIComponent(projectKey)}/tracking-values`),
    beginReview: (publicId: string) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/begin-review`, { method: 'POST' }),
    requestInformation: (publicId: string, request: string) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/information-requests`, { method: 'POST', body: { request } }),
    respondInformation: (publicId: string, response: string) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/information-responses`, { method: 'POST', body: { response } }),
    assign: (publicId: string, assigneeId: string | null) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/assignments`, { method: 'POST', body: { assignee_id: assigneeId } }),
    setPriority: (publicId: string, priorityId: string) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/priority`, { method: 'POST', body: { priority_id: priorityId } }),
    setSeverity: (publicId: string, severityId: string) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/severity`, { method: 'POST', body: { severity_id: severityId } }),
    assignedToMe: () => api.request<{ data: BugReport[] }>('/bugs/assigned-to-me'),
    startWork: (publicId: string) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/start-work`, { method: 'POST' }),
    addProgress: (publicId: string, body: string) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/progress-updates`, { method: 'POST', body: { body } }),
    resolveFixed: (publicId: string, explanation: string, qaInstructions: string) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/fixed-resolutions`, { method: 'POST', body: { explanation, qa_instructions: qaInstructions } }),
    resolveNonFix: (publicId: string, data: NonFixResolutionData) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/non-fix-resolutions`, { method: 'POST', body: data }),
    qaQueue: () => api.request<{ data: BugReport[] }>('/bugs/qa-queue'),
    verify: (publicId: string, decision: QAVerificationDecision, notes: string) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/verifications`, { method: 'POST', body: { decision, notes } }),
    resumeWork: (publicId: string) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/resume-work`, { method: 'POST' }),
    renewReview: (publicId: string) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/renew-review`, { method: 'POST' }),
    reopen: (publicId: string, reason: string) => api.request<{ data: BugReport }>(`/bugs/${encodeURIComponent(publicId)}/reopen`, { method: 'POST', body: { reason } }),
    activity: (publicId: string, page = 1, perPage = 25) => api.request<ActivityPage>(`/bugs/${encodeURIComponent(publicId)}/activity?page=${page}&per_page=${perPage}`),
    relationships: (publicId: string) => api.request<{ data: BugRelationship[] }>(`/bugs/${encodeURIComponent(publicId)}/relationships`),
    createRelationship: (publicId: string, type: BugRelationshipType, targetBugId: string) => api.request<{ data: BugRelationship }>(`/bugs/${encodeURIComponent(publicId)}/relationships`, { method: 'POST', body: { type, target_bug_id: targetBugId } }),
    deactivateRelationship: (relationshipId: string) => api.request<void>(`/relationships/${encodeURIComponent(relationshipId)}`, { method: 'DELETE' }),
    attachments: (publicId: string) => api.request<{ data: BugAttachment[] }>(`/bugs/${encodeURIComponent(publicId)}/attachments`),
    uploadAttachment: (publicId: string, file: File) => {
      const body = new FormData()
      body.append('file', file)
      return api.request<{ data: BugAttachment }>(`/bugs/${encodeURIComponent(publicId)}/attachments`, { method: 'POST', body })
    },
    downloadAttachment: (downloadUrl: string) => api.request<Blob>(downloadUrl, { responseType: 'blob' }),
    removeAttachment: (attachmentId: string) => api.request<void>(`/attachments/${encodeURIComponent(attachmentId)}`, { method: 'DELETE' }),
  }
}
