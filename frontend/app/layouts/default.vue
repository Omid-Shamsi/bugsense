<script setup lang="ts">
import type { DropdownMenuItem, NavigationMenuItem } from '@nuxt/ui'

const route = useRoute()
const session = useSession()
const workspace = useWorkspace()
const sidebarOpen = ref(false)
const signingOut = ref(false)

const navigation = computed<NavigationMenuItem[]>(() => [{
  label: 'داشبورد',
  icon: 'i-lucide-layout-dashboard',
  to: '/dashboard',
  onSelect: closeSidebar
}, {
  label: 'باگ‌ها',
  icon: 'i-lucide-bug',
  to: '/bugs',
  onSelect: closeSidebar
}, {
  label: 'ثبت باگ',
  icon: 'i-lucide-circle-plus',
  to: '/bugs/new',
  onSelect: closeSidebar
}, {
  label: 'کارهای توسعه‌دهنده',
  icon: 'i-lucide-code-xml',
  to: '/work/developer',
  onSelect: closeSidebar
}, {
  label: 'بررسی QA',
  icon: 'i-lucide-clipboard-check',
  to: '/work/qa',
  onSelect: closeSidebar
}, {
  label: 'مدیریت',
  icon: 'i-lucide-settings-2',
  to: '/projects',
  onSelect: closeSidebar
}])

const pageTitle = computed(() => {
  if (route.path === '/dashboard') return 'داشبورد'
  if (route.path === '/bugs/new') return 'ثبت باگ'
  if (route.path.startsWith('/bugs/')) return 'جزئیات باگ'
  if (route.path === '/bugs') return 'باگ‌ها'
  if (route.path === '/work/developer') return 'کارهای توسعه‌دهنده'
  if (route.path === '/work/qa') return 'بررسی QA'
  if (route.path.startsWith('/admin/projects/')) return 'مدیریت پروژه'
  if (route.path === '/admin/users') return 'مدیریت کاربران'
  if (route.path.startsWith('/admin')) return 'مدیریت'
  if (route.path === '/projects') return 'پروژه‌ها'
  if (route.path.startsWith('/projects/')) return 'پروژه'
  if (route.path === '/users') return 'کاربران'
  return 'BugSense'
})

const userName = computed(() => session.user.value?.display_name || session.user.value?.email || 'کاربر')
const userInitial = computed(() => userName.value.trim().charAt(0).toUpperCase() || 'B')
const userMenuItems = computed<DropdownMenuItem[][]>(() => [[{
  type: 'label',
  label: userName.value
}], [{
  label: signingOut.value ? 'در حال خروج…' : 'خروج',
  icon: 'i-lucide-log-out',
  disabled: signingOut.value,
  onSelect: () => { void signOut() }
}]])

function closeSidebar() {
  sidebarOpen.value = false
}

async function signOut() {
  if (signingOut.value) return
  signingOut.value = true
  try {
    await session.logout()
    workspace.clear()
    await navigateTo('/login')
  } finally {
    signingOut.value = false
  }
}
</script>

<template>
  <UDashboardGroup unit="rem" storage-key="bugsense-dashboard">
    <UDashboardSidebar
      id="primary"
      v-model:open="sidebarOpen"
      side="right"
      toggle-side="right"
      :menu="{ side: 'right' }"
      collapsible
      resizable
      :min-size="15"
      :max-size="22"
      :default-size="17"
      :collapsed-size="4"
      class="bugsense-sidebar bg-elevated/25"
      :ui="{ footer: 'lg:border-t lg:border-default' }"
    >
      <template #header="{ collapsed }">
        <div class="sidebar-header" :class="{ 'sidebar-header--collapsed': collapsed }">
          <NuxtLink class="sidebar-brand" to="/dashboard" aria-label="BugSense">
            <span class="brand-mark" aria-hidden="true">B</span>
            <span v-if="!collapsed" class="sidebar-brand-name">BugSense</span>
          </NuxtLink>
        </div>
      </template>

      <template #default="{ collapsed }">
        <WorkspaceSwitcher v-if="!collapsed" />

        <UNavigationMenu
          :collapsed="collapsed"
          :items="navigation"
          orientation="vertical"
          tooltip
          popover
          class="sidebar-navigation"
        />
        <NuxtLink v-if="session.user.value?.is_system_admin && !collapsed" to="/users" class="sidebar-users-link">کاربران</NuxtLink>
      </template>

      <template #footer="{ collapsed }">
        <UDropdownMenu
          :items="userMenuItems"
          :content="{ align: 'center', collisionPadding: 12 }"
          :ui="{ content: collapsed ? 'w-52' : 'w-(--reka-dropdown-menu-trigger-width)' }"
        >
          <UButton
            color="neutral"
            variant="ghost"
            block
            :square="collapsed"
            class="sidebar-user data-[state=open]:bg-elevated"
            :aria-label="`حساب ${userName}`"
          >
            <span class="sidebar-user-avatar" aria-hidden="true">{{ userInitial }}</span>
            <span v-if="!collapsed" class="sidebar-user-copy">
              <span :dir="session.user.value?.display_name ? undefined : 'ltr'">{{ userName }}</span>
              <small>حساب کاربری</small>
            </span>
            <UIcon v-if="!collapsed" name="i-lucide-chevrons-up-down" class="sidebar-user-chevron" />
          </UButton>
        </UDropdownMenu>
      </template>
    </UDashboardSidebar>

    <UDashboardPanel id="main" class="bugsense-main-panel" :ui="{ body: 'p-0 sm:p-0 gap-0' }">
      <template #header>
        <UDashboardNavbar :title="pageTitle" toggle-side="right" class="bugsense-navbar">
          <template #leading>
            <UDashboardSidebarCollapse side="right" />
          </template>

          <template #right>
            <UButton
              to="/bugs/new"
              icon="i-lucide-plus"
              label="ثبت باگ"
              size="sm"
              class="navbar-new-bug"
            />
          </template>
        </UDashboardNavbar>
      </template>

      <template #body>
        <div class="app-content">
          <slot />
        </div>
      </template>
    </UDashboardPanel>
  </UDashboardGroup>
</template>
