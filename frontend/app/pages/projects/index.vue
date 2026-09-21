<script setup lang="ts">
import { adminErrorMessage, useAdministration, type AdminProject } from '~/composables/useAdministration'

definePageMeta({ middleware: 'auth' })
const admin = useAdministration()
const session = useSession()
const workspace = useWorkspace()
const projects = ref<AdminProject[]>([])
const loading = ref(true)
const error = ref('')
const search = ref('')
const state = ref<'all' | 'active' | 'inactive'>('all')
const createOpen = ref(false)
const pending = ref(false)
const fieldErrors = ref<Record<string, string[]>>({})
const form = reactive({ name: '', key: '', description: '' })
const isSystemAdmin = computed(() => session.user.value?.is_system_admin || false)
const visibleProjects = computed(() => projects.value.filter(project => {
  const query = search.value.trim().toLocaleLowerCase()
  return (!query || [project.name, project.key, project.description || ''].some(value => value.toLocaleLowerCase().includes(query))) && (!isSystemAdmin.value || state.value === 'all' || (state.value === 'active') === project.active)
}))
function rolesFor(project: AdminProject) { return session.user.value?.memberships.find(membership => membership.active && membership.project.key === project.key)?.roles || [] }
function projectAdmin(project: AdminProject) { return rolesFor(project).includes('admin') && project.active }
async function load() { loading.value = true; error.value = ''; try { projects.value = (await admin.listProjects()).data } catch (caught) { error.value = adminErrorMessage(caught) } finally { loading.value = false } }
async function createProject() { if (pending.value) return; pending.value = true; fieldErrors.value = {}; error.value = ''; try { const result = await admin.createProject({ name: form.name.trim(), key: form.key.trim().toUpperCase(), description: form.description.trim() || null }); createOpen.value = false; Object.assign(form, { name: '', key: '', description: '' }); await workspace.refresh(); await navigateTo(`/projects/${encodeURIComponent(result.data.key)}`) } catch (caught: any) { error.value = adminErrorMessage(caught); fieldErrors.value = caught?.fieldErrors || {} } finally { pending.value = false } }
await load()
</script>

<template>
  <main class="projects-directory">
    <section class="projects-toolbar"><div class="projects-toolbar__controls"><UInput v-model="search" icon="i-lucide-search" placeholder="جست‌وجوی نام یا کلید پروژه…" :disabled="loading" /><USelect v-if="isSystemAdmin" v-model="state" :items="[{ label: 'همه', value: 'all' }, { label: 'فعال', value: 'active' }, { label: 'غیرفعال', value: 'inactive' }]" :disabled="loading" /></div><div class="projects-toolbar__end"><span v-if="!loading && !error" class="projects-count">{{ visibleProjects.length }} پروژه</span><UButton v-if="isSystemAdmin" size="sm" icon="i-lucide-plus" @click="createOpen = true">ایجاد پروژه</UButton></div></section>
    <section v-if="loading" class="projects-loading" aria-busy="true"><USkeleton class="h-9 w-full" /><USkeleton v-for="index in 7" :key="index" class="h-14 w-full" /></section>
    <section v-else-if="error" class="projects-state"><UAlert color="error" variant="subtle" title="بارگیری پروژه‌ها انجام نشد" :description="error"><template #actions><UButton size="xs" color="error" variant="outline" @click="load">تلاش مجدد</UButton></template></UAlert></section>
    <section v-else-if="!projects.length" class="projects-state"><h2>{{ isSystemAdmin ? 'هنوز پروژه‌ای ایجاد نشده است.' : 'هنوز به پروژهٔ فعالی دسترسی ندارید.' }}</h2><UButton v-if="isSystemAdmin" @click="createOpen = true">ایجاد پروژه</UButton></section>
    <section v-else-if="!visibleProjects.length" class="projects-state"><h2>پروژه‌ای با این جست‌وجو پیدا نشد.</h2><UButton color="neutral" variant="ghost" @click="search = ''; state = 'all'">پاک‌کردن جست‌وجو</UButton></section>
    <div v-else class="projects-table-wrap"><table class="projects-table"><thead><tr><th>پروژه</th><th>کلید</th><th>دسترسی من</th><th>وضعیت</th><th><span class="sr-only">اقدام</span></th></tr></thead><tbody><tr v-for="project in visibleProjects" :key="project.id"><td><NuxtLink class="projects-table__name" :to="`/projects/${encodeURIComponent(project.key)}`"><strong dir="auto">{{ project.name }}</strong><small v-if="project.description" dir="auto">{{ project.description }}</small></NuxtLink></td><td><TechnicalValue :value="project.key" /></td><td><RoleBadges :roles="rolesFor(project)" :system-admin="isSystemAdmin" :project-admin="projectAdmin(project)" /></td><td><UBadge :color="project.active ? 'success' : 'warning'" variant="subtle">{{ project.active ? 'فعال' : 'غیرفعال' }}</UBadge></td><td><UButton :to="`/projects/${encodeURIComponent(project.key)}`" size="sm" color="neutral" variant="outline">بازکردن</UButton></td></tr></tbody></table></div>
  </main>
  <UModal :open="createOpen" title="ایجاد پروژه" description="ایجاد پروژه به‌تنهایی عضو، نقش یا مقدار پیگیری ایجاد نمی‌کند." @update:open="value => { if (!pending) createOpen = value }"><template #body><UAlert v-if="error" color="error" variant="subtle" :description="error" class="mb-4" /><form class="projects-form" @submit.prevent="createProject"><UFormField label="نام پروژه" :error="fieldErrors.name?.[0]"><UInput v-model="form.name" :disabled="pending" maxlength="150" /></UFormField><UFormField label="کلید پروژه" description="حروف بزرگ انگلیسی، رقم و خط تیره؛ ۲ تا ۲۰ کاراکتر." :error="fieldErrors.key?.[0]"><UInput v-model="form.key" dir="ltr" :disabled="pending" maxlength="20" /></UFormField><UFormField label="توضیحات"><UTextarea v-model="form.description" :disabled="pending" /></UFormField></form></template><template #footer><UButton color="neutral" variant="ghost" :disabled="pending" @click="createOpen = false">انصراف</UButton><UButton :loading="pending" @click="createProject">ایجاد پروژه</UButton></template></UModal>
</template>

<style scoped>
.projects-directory{padding:1.5rem}.projects-toolbar{align-items:center;border-bottom:1px solid var(--ui-border);display:flex;gap:1rem;justify-content:space-between;padding-bottom:1rem}.projects-toolbar__controls,.projects-toolbar__end{align-items:center;display:flex;flex-wrap:wrap;gap:.5rem}.projects-toolbar__controls :deep(input){min-width:18rem}.projects-count{color:var(--ui-text-muted);font-size:.8rem}.projects-loading{display:grid;gap:.65rem;margin-top:1.25rem}.projects-state{margin-top:1.25rem}.projects-state h2{font-size:1rem;margin:0 0 .75rem}.projects-table-wrap{margin-top:1.25rem}.projects-table{border-collapse:collapse;table-layout:fixed;width:100%}.projects-table th{color:var(--ui-text-muted);font-size:.72rem;font-weight:650;padding:.65rem .75rem;text-align:start}.projects-table td{border-top:1px solid var(--ui-border);font-size:.84rem;padding:.85rem .75rem;vertical-align:top}.projects-table th:nth-child(1){width:36%}.projects-table th:nth-child(2){width:13%}.projects-table th:nth-child(3){width:28%}.projects-table th:nth-child(4){width:12%}.projects-table th:nth-child(5){width:11%}.projects-table__name{color:var(--ui-text-highlighted);display:grid;gap:.2rem;text-decoration:none}.projects-table__name:hover strong{text-decoration:underline;text-underline-offset:.2rem}.projects-table__name small{color:var(--ui-text-muted);display:-webkit-box;overflow:hidden;-webkit-box-orient:vertical;-webkit-line-clamp:1}.projects-form{display:grid;gap:1rem}@media(max-width:767px){.projects-directory{padding:1rem}.projects-toolbar{align-items:stretch;flex-direction:column}.projects-toolbar__controls{align-items:stretch;flex-direction:column}.projects-toolbar__controls :deep(input){min-width:0}.projects-toolbar__end{justify-content:space-between}.projects-table th:nth-child(2),.projects-table td:nth-child(2){display:none}.projects-table th:nth-child(3){width:35%}.projects-table th:nth-child(1){width:39%}.projects-table th:nth-child(4){width:15%}.projects-table th:nth-child(5){width:11%}}
</style>
