import type { ApiError } from '~/plugins/api.client'

export interface AdminProject { id: string; key: string; name: string; description: string | null; active: boolean }
export interface AdminUser { id: string; email: string; display_name: string; is_system_admin: boolean; active: boolean; created_at: string; updated_at: string }
export interface AdminMembership { id: string; project_id: string; user_id: string; roles: string[]; active: boolean }
export interface AdminTrackingValue { id: string; project_id: string; kind: TrackingKind; code: string; name: string; rank: number | null; active: boolean }
export type TrackingKind = 'category' | 'priority' | 'severity' | 'tag' | 'resolution_label'
export interface ApiCollection<T> { data: T[]; meta?: { current_page: number; last_page: number; per_page: number; total: number } }
export type AssignmentRemediation = { unassign: true } | { reassign_to: string }
export type TrackingRemediation = { unset: true } | { replace_with: string }

export function adminErrorMessage(error: unknown): string {
  const apiError = error as ApiError
  if (apiError?.status === 403) return 'سرور اجازه این عملیات مدیریتی را نداد.'
  if (apiError?.status === 409) return apiError.problem?.detail || 'سرور یک تداخل گزارش کرد. صفحه را به‌روز و دوباره تلاش کنید.'
  if (apiError?.status === 422) return apiError.problem?.detail || 'فیلدهای مشخص‌شده را بررسی و دوباره تلاش کنید.'
  if (apiError?.kind === 'network') return 'ارتباط با BugSense برقرار نشد. اتصال را بررسی و دوباره تلاش کنید.'
  return apiError?.problem?.detail || 'سرور نتوانست این عملیات را انجام دهد.'
}

export function adminFieldErrors(error: unknown): Record<string, string[]> {
  return ((error as ApiError)?.fieldErrors) || {}
}

export function useAdministration() {
  const api = useApi()

  return {
    listUsers: (q = '', page = 1) => api.request<ApiCollection<AdminUser>>('/users', { query: { q: q || undefined, page, per_page: 25 } }),
    createUser: (data: Pick<AdminUser, 'email' | 'display_name'> & { password: string; is_system_admin: boolean }) => api.request<{ data: AdminUser }>('/users', { method: 'POST', body: data }),
    updateUser: (id: string, data: Partial<Pick<AdminUser, 'email' | 'display_name' | 'is_system_admin' | 'active'>> & { password?: string; remediation?: AssignmentRemediation }) => api.request<{ data: AdminUser }>(`/users/${id}`, { method: 'PATCH', body: data }),
    createProject: (data: Pick<AdminProject, 'key' | 'name' | 'description'>) => api.request<{ data: AdminProject }>('/projects', { method: 'POST', body: data }),
    getProject: (key: string) => api.request<{ data: AdminProject }>(`/projects/${encodeURIComponent(key)}`),
    updateProject: (key: string, data: Partial<Pick<AdminProject, 'key' | 'name' | 'description' | 'active'>>) => api.request<{ data: AdminProject }>(`/projects/${encodeURIComponent(key)}`, { method: 'PATCH', body: data }),
    listMemberships: (key: string) => api.request<ApiCollection<AdminMembership>>(`/projects/${encodeURIComponent(key)}/memberships`),
    createMembership: (key: string, data: { user_id: string; roles: string[] }) => api.request<{ data: AdminMembership }>(`/projects/${encodeURIComponent(key)}/memberships`, { method: 'POST', body: data }),
    updateMembership: (id: string, data: Partial<Pick<AdminMembership, 'roles' | 'active'>> & { remediation?: AssignmentRemediation }) => api.request<{ data: AdminMembership }>(`/memberships/${id}`, { method: 'PATCH', body: data }),
    listTrackingValues: (key: string) => api.request<ApiCollection<AdminTrackingValue>>(`/projects/${encodeURIComponent(key)}/tracking-values`),
    createTrackingValue: (key: string, data: { kind: TrackingKind; code: string; name: string; rank: number | null }) => api.request<{ data: AdminTrackingValue }>(`/projects/${encodeURIComponent(key)}/tracking-values`, { method: 'POST', body: data }),
    updateTrackingValue: (id: string, data: Partial<Pick<AdminTrackingValue, 'name' | 'rank' | 'active'>> & { remediation?: TrackingRemediation }) => api.request<{ data: AdminTrackingValue }>(`/tracking-values/${id}`, { method: 'PATCH', body: data }),
  }
}
