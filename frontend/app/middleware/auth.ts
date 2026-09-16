export default defineNuxtRouteMiddleware(async () => {
  const session = useSession()

  if (session.status.value !== 'authenticated') await session.refresh()
  if (!session.isAuthenticated.value) return navigateTo('/login')
})
