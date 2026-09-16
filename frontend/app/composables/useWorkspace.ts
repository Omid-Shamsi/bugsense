import type { CurrentUserMembership } from '~/plugins/api.client'

export interface VisibleProject {
  id: string
  key: string
  name: string
  description: string | null
  active: boolean
}

let observerAttached = false

function roleLabel(role: string): string {
  return role.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
}

export function useWorkspace() {
  const api = useApi()
  const session = useSession()
  const projects = useState<VisibleProject[]>('workspace-projects', () => [])
  const selectedKey = useState<string | null>('workspace-selected-key', () => null)
  const loading = useState('workspace-loading', () => false)

  const selectedProject = computed(() => projects.value.find((project) => project.key === selectedKey.value) || null)
  const selectedMembership = computed<CurrentUserMembership | null>(() => {
    const key = selectedProject.value?.key
    if (!key) return null
    return session.user.value?.memberships.find((membership) => membership.active && membership.project.key === key) || null
  })
  const selectedRoles = computed(() => {
    if (selectedMembership.value) return selectedMembership.value.roles.map(roleLabel)
    return session.user.value?.is_system_admin ? ['System Admin'] : []
  })

  function clear() {
    projects.value = []
    selectedKey.value = null
  }

  async function refresh() {
    if (!session.user.value) {
      clear()
      return
    }

    loading.value = true
    try {
      projects.value = (await api.request<{ data: VisibleProject[] }>('/projects')).data
      if (!projects.value.some((project) => project.key === selectedKey.value)) {
        selectedKey.value = projects.value[0]?.key || null
      }
    } finally {
      loading.value = false
    }
  }

  function selectProject(key: string) {
    if (projects.value.some((project) => project.key === key)) selectedKey.value = key
  }

  if (!observerAttached) {
    observerAttached = true
    watch(() => session.user.value, () => { void refresh() }, { immediate: true })
  }

  return {
    projects: readonly(projects),
    selectedProject: readonly(selectedProject),
    selectedRoles: readonly(selectedRoles),
    selectedKey: readonly(selectedKey),
    loading: readonly(loading),
    refresh,
    clear,
    selectProject,
  }
}
