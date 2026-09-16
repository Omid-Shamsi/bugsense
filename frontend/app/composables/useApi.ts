import type { ApiClient } from '~/plugins/api.client'

export function useApi(): ApiClient {
  return useNuxtApp().$api
}
