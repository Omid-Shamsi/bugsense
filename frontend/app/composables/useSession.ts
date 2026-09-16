import { ApiError, type CurrentUser } from '~/plugins/api.client'

export type SessionStatus = 'loading' | 'authenticated' | 'signed-out'

let refreshInFlight: Promise<void> | undefined

export function useSession() {
  const api = useApi()
  const user = useState<CurrentUser | null>('session-user', () => null)
  const status = useState<SessionStatus>('session-status', () => 'loading')

  async function refresh(): Promise<void> {
    if (refreshInFlight) return refreshInFlight

    refreshInFlight = (async () => {
      status.value = 'loading'
      try {
        user.value = (await api.getCurrentUser()).data
        status.value = 'authenticated'
      } catch (error) {
        user.value = null
        status.value = 'signed-out'
        if (!(error instanceof ApiError) || error.kind !== 'unauthenticated') throw error
      } finally {
        refreshInFlight = undefined
      }
    })()

    return refreshInFlight
  }

  async function login(email: string, password: string): Promise<void> {
    await api.login(email, password)
    await refresh()
  }

  async function logout(): Promise<void> {
    try {
      await api.logout()
    } catch (error) {
      if (!(error instanceof ApiError) || error.kind !== 'unauthenticated') throw error
    }

    user.value = null
    status.value = 'signed-out'
  }

  return {
    user: readonly(user),
    status: readonly(status),
    isAuthenticated: computed(() => status.value === 'authenticated'),
    refresh,
    login,
    logout,
  }
}
