import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, createApiClient } from '../../app/plugins/api.client'

const apiBase = 'http://localhost:8000/api/v1'
const backendOrigin = 'http://localhost:8000'

function problem(status: number, title: string, errors?: Record<string, string[]>) {
  return {
    response: {
      status,
      _data: {
        type: 'about:blank',
        title,
        status,
        detail: title,
        instance: 'login',
        ...(errors ? { errors } : {}),
      },
    },
  }
}

describe('credentialed API client', () => {
  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=; Max-Age=0; path=/'
  })

  it('bootstraps CSRF then sends cookies and decoded XSRF token for login', async () => {
    document.cookie = 'XSRF-TOKEN=token%3Dvalue; path=/'
    const fetcher = vi.fn()
      .mockResolvedValueOnce({ _data: undefined })
      .mockResolvedValueOnce({ _data: undefined })
    const api = createApiClient(apiBase, backendOrigin, fetcher)

    await api.login('person@example.com', 'secret')

    expect(fetcher).toHaveBeenNthCalledWith(1, `${backendOrigin}/sanctum/csrf-cookie`, expect.objectContaining({
      credentials: 'include',
      headers: { Accept: 'application/json' },
    }))
    const options = fetcher.mock.calls[1][1]
    expect(fetcher.mock.calls[1][0]).toBe(`${backendOrigin}/login`)
    expect(options.credentials).toBe('include')
    expect(options.baseURL).toBe(apiBase)
    expect(options.headers.get('X-XSRF-TOKEN')).toBe('token=value')
  })

  it('normalizes a problem+json 401 as signed-out state', async () => {
    const api = createApiClient(apiBase, backendOrigin, vi.fn().mockRejectedValue(problem(401, 'Unauthenticated')))

    await expect(api.getCurrentUser()).rejects.toMatchObject<ApiError>({
      kind: 'unauthenticated',
      status: 401,
      problem: expect.objectContaining({ title: 'Unauthenticated' }),
    })
  })

  it('preserves field errors from a validation problem', async () => {
    const fetcher = vi.fn()
      .mockResolvedValueOnce({ _data: undefined })
      .mockRejectedValueOnce(problem(422, 'Unprocessable Entity', { email: ['These credentials do not match our records.'] }))
    const api = createApiClient(apiBase, backendOrigin, fetcher)

    await expect(api.login('person@example.com', 'wrong')).rejects.toMatchObject<ApiError>({
      kind: 'validation',
      fieldErrors: { email: ['These credentials do not match our records.'] },
    })
  })

  it('normalizes 419 and reacquires CSRF before next mutation', async () => {
    const fetcher = vi.fn()
      .mockResolvedValueOnce({ _data: undefined })
      .mockRejectedValueOnce(problem(419, 'CSRF Token Mismatch'))
      .mockResolvedValueOnce({ _data: undefined })
      .mockResolvedValueOnce({ _data: undefined })
    const api = createApiClient(apiBase, backendOrigin, fetcher)

    await expect(api.login('person@example.com', 'secret')).rejects.toMatchObject<ApiError>({ kind: 'csrf', status: 419 })
    await api.login('person@example.com', 'secret')

    expect(fetcher.mock.calls.filter(([url]) => url === `${backendOrigin}/sanctum/csrf-cookie`)).toHaveLength(2)
  })
})
