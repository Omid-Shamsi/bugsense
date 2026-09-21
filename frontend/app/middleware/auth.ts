export default defineNuxtRouteMiddleware(async (to) => {
  const session = useSession()

  if (session.status.value !== 'authenticated') await session.refresh()
  if (!session.isAuthenticated.value) return navigateTo({ path: '/login', query: { returnTo: to.fullPath } })
})
