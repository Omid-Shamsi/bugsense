import { $fetch, type FetchOptions } from 'ofetch'

export interface CurrentUserMembership {
  id: string
  project: { id: string; key: string; name: string; active: boolean }
  roles: readonly string[]
  active: boolean
}

export interface CurrentUser {
  id: string
  email: string
  display_name: string
  is_system_admin: boolean
  memberships: CurrentUserMembership[]
}

export interface ApiProblem {
  type: string
  title: string
  status: number
  detail: string
  instance: string
  errors?: Record<string, string[]>
}

export type ApiErrorKind = 'unauthenticated' | 'csrf' | 'validation' | 'network' | 'unexpected'

export class ApiError extends Error {
  constructor(
    public readonly kind: ApiErrorKind,
    public readonly status?: number,
    public readonly problem?: ApiProblem,
  ) {
    super(problem?.detail || problem?.title || 'Unable to complete request.')
    this.name = 'ApiError'
  }

  get fieldErrors(): Record<string, string[]> {
    return this.problem?.errors || {}
  }
}

type Fetcher = <T>(request: string, options?: FetchOptions) => Promise<{ _data: T }>
type RequestOptions = Omit<FetchOptions, 'baseURL' | 'credentials' | 'headers'> & { headers?: HeadersInit }

const unsafeMethods = new Set(['POST', 'PUT', 'PATCH', 'DELETE'])

function readXsrfToken(): string | undefined {
  if (typeof document === 'undefined') return undefined

  const token = document.cookie
    .split('; ')
    .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
    ?.split('=')
    .slice(1)
    .join('=')

  return token ? decodeURIComponent(token) : undefined
}

function problemFrom(error: unknown): ApiProblem | undefined {
  const response = (error as { response?: { _data?: unknown } })?.response
  const data = response?._data

  if (!data || typeof data !== 'object') return undefined

  const problem = data as Partial<ApiProblem>
  if (typeof problem.status !== 'number' || typeof problem.title !== 'string') return undefined

  return {
    type: problem.type || 'about:blank',
    title: problem.title,
    status: problem.status,
    detail: problem.detail || problem.title,
    instance: problem.instance || '',
    ...(problem.errors ? { errors: problem.errors } : {}),
  }
}

function normalizeError(error: unknown): ApiError {
  if (error instanceof ApiError) return error

  const response = (error as { response?: { status?: number } })?.response
  const status = response?.status
  const problem = problemFrom(error)

  if (status === 401) return new ApiError('unauthenticated', status, problem)
  if (status === 419) return new ApiError('csrf', status, problem)
  if (status === 422) return new ApiError('validation', status, problem)
  if (!response) return new ApiError('network')
  return new ApiError('unexpected', status, problem)
}

export function createApiClient(apiBase: string, backendOrigin: string, fetcher: Fetcher = $fetch.raw as Fetcher) {
  let csrfReady = false

  async function bootstrapCsrf(): Promise<void> {
    try {
      await fetcher(`${backendOrigin}/sanctum/csrf-cookie`, {
        credentials: 'include',
        headers: { Accept: 'application/json' },
      })
      csrfReady = true
    } catch (error) {
      throw normalizeError(error)
    }
  }

  async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
    const method = (options.method || 'GET').toUpperCase()

    if (unsafeMethods.has(method) && !csrfReady) await bootstrapCsrf()

    const headers = new Headers(options.headers)
    headers.set('Accept', 'application/json')

    if (unsafeMethods.has(method)) {
      const xsrfToken = readXsrfToken()
      if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken)
    }

    try {
      const response = await fetcher<T>(path, {
        ...options,
        baseURL: apiBase,
        credentials: 'include',
        headers,
      })
      return response._data
    } catch (error) {
      const normalized = normalizeError(error)
      if (normalized.kind === 'csrf') csrfReady = false
      throw normalized
    }
  }

  return {
    bootstrapCsrf,
    getCurrentUser: () => request<{ data: CurrentUser }>('/me'),
    login: (email: string, password: string) => request<void>(`${backendOrigin}/login`, {
      method: 'POST',
      body: { email, password },
    }),
    logout: () => request<void>(`${backendOrigin}/logout`, { method: 'POST' }),
    request,
  }
}

export type ApiClient = ReturnType<typeof createApiClient>

export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig()
  const api = createApiClient(config.public.apiBase, config.public.backendOrigin)

  return { provide: { api } }
})
