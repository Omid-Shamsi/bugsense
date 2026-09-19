import { execFileSync } from 'node:child_process'
import { mkdirSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect, test as setup, type Browser, type BrowserContext, type Page } from '@playwright/test'

const here = dirname(fileURLToPath(import.meta.url))
const frontendRoot = resolve(here, '../..')
const backendRoot = resolve(frontendRoot, '../backend')
const authDir = resolve(frontendRoot, 'playwright/.auth')
const frontendUrl = process.env.E2E_FRONTEND_URL || 'http://localhost:3000'
const backendUrl = process.env.E2E_BACKEND_URL || 'http://localhost:8000'
const apiUrl = `${backendUrl}/api/v1`
const systemAdminEmail = process.env.E2E_SYSTEM_ADMIN_EMAIL || 'e2e.system-admin@bugsense.test'
const password = process.env.E2E_TEST_PASSWORD || ''

type RoleName = 'reporter' | 'projectAdmin' | 'developer' | 'qa' | 'outsider' | 'systemAdmin'

interface CreatedUser {
  id: string
  email: string
  display_name: string
}

interface CreatedProject {
  id: string
  key: string
  name: string
}

function requirePassword(): void {
  if (password.length < 12) {
    throw new Error('E2E_TEST_PASSWORD must be set to at least 12 characters before running Playwright.')
  }
}

function bootstrapSystemAdmin(): void {
  const php = String.raw`
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$email = getenv('E2E_SYSTEM_ADMIN_EMAIL');
$password = getenv('E2E_TEST_PASSWORD');
$user = App\Models\User::query()->firstOrNew(['email' => $email]);
$user->forceFill([
    'display_name' => 'E2E System Admin',
    'password' => $password,
    'is_system_admin' => true,
    'is_active' => true,
    'deactivated_at' => null,
    'deactivated_by_id' => null,
])->save();
`

  execFileSync(process.env.E2E_PHP_BINARY || 'php', ['-r', php], {
    cwd: backendRoot,
    env: {
      ...process.env,
      DB_HOST: process.env.E2E_DB_HOST || '127.0.0.1',
      DB_PORT: process.env.E2E_DB_PORT || '5432',
      E2E_SYSTEM_ADMIN_EMAIL: systemAdminEmail,
      E2E_TEST_PASSWORD: password,
    },
    stdio: 'pipe',
  })
}

async function login(browser: Browser, email: string, stateName: RoleName): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext()
  const page = await context.newPage()

  await page.goto('/login')
  await page.getByLabel('ایمیل').fill(email)
  await page.getByLabel('رمز عبور').fill(password)
  await page.getByRole('button', { name: 'ورود' }).click()
  await expect(page.getByRole('heading', { name: 'شما وارد شده‌اید' })).toBeVisible()

  const me = await page.request.get(`${apiUrl}/me`, {
    headers: { Accept: 'application/json', Origin: frontendUrl, Referer: `${frontendUrl}/` },
  })
  expect(me.ok(), `GET /me failed for ${stateName}: ${me.status()} ${await me.text()}`).toBeTruthy()
  await context.storageState({ path: resolve(authDir, `${stateName}.json`) })

  return { context, page }
}

async function xsrfHeaders(context: BrowserContext): Promise<Record<string, string>> {
  const cookie = (await context.cookies(backendUrl)).find((item) => item.name === 'XSRF-TOKEN')
  if (!cookie) throw new Error('Laravel did not issue an XSRF-TOKEN cookie after login.')

  return {
    Accept: 'application/json',
    Origin: frontendUrl,
    Referer: `${frontendUrl}/`,
    'X-XSRF-TOKEN': decodeURIComponent(cookie.value),
  }
}

async function postJson<T>(page: Page, context: BrowserContext, path: string, data: unknown): Promise<T> {
  const response = await page.request.post(`${apiUrl}${path}`, {
    data,
    headers: await xsrfHeaders(context),
  })
  if (!response.ok()) throw new Error(`POST ${path} failed: ${response.status()} ${await response.text()}`)
  return response.json() as Promise<T>
}

setup('provision isolated data and save real Sanctum sessions', async ({ browser }) => {
  requirePassword()
  mkdirSync(authDir, { recursive: true })
  bootstrapSystemAdmin()

  const runId = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`.toUpperCase()
  const primaryKey = `E2E${runId}`.slice(0, 20)
  const isolatedKey = `X${runId}`.slice(0, 20)
  const primaryName = `E2E Core ${runId}`
  const isolatedName = `E2E Isolated ${runId}`
  const { context, page } = await login(browser, systemAdminEmail, 'systemAdmin')

  const primaryProject = (await postJson<{ data: CreatedProject }>(page, context, '/projects', {
    key: primaryKey,
    name: primaryName,
    description: `Isolated Playwright project ${runId}`,
  })).data
  const isolatedProject = (await postJson<{ data: CreatedProject }>(page, context, '/projects', {
    key: isolatedKey,
    name: isolatedName,
    description: `Cross-project denial fixture ${runId}`,
  })).data

  const userSpecs: Record<Exclude<RoleName, 'systemAdmin'>, { label: string; roles: string[]; project: CreatedProject }> = {
    reporter: { label: 'Reporter', roles: ['reporter'], project: primaryProject },
    projectAdmin: { label: 'Project Admin', roles: ['admin'], project: primaryProject },
    developer: { label: 'Developer', roles: ['developer'], project: primaryProject },
    qa: { label: 'QA', roles: ['qa'], project: primaryProject },
    outsider: { label: 'Outsider Reporter', roles: ['reporter'], project: isolatedProject },
  }
  const users = {} as Record<Exclude<RoleName, 'systemAdmin'>, CreatedUser>

  for (const [role, spec] of Object.entries(userSpecs) as [Exclude<RoleName, 'systemAdmin'>, typeof userSpecs[Exclude<RoleName, 'systemAdmin'>]][]) {
    const email = `e2e.${runId.toLowerCase()}.${role.toLowerCase()}@bugsense.test`
    users[role] = (await postJson<{ data: CreatedUser }>(page, context, '/users', {
      email,
      display_name: `E2E ${spec.label} ${runId}`,
      password,
      is_system_admin: false,
    })).data
    await postJson(page, context, `/projects/${spec.project.key}/memberships`, {
      user_id: users[role].id,
      roles: spec.roles,
    })
  }

  const priority = (await postJson<{ data: { id: string; name: string } }>(page, context, `/projects/${primaryProject.key}/tracking-values`, {
    kind: 'priority', code: 'e2e-high', name: `E2E High ${runId}`, rank: 10,
  })).data
  const severity = (await postJson<{ data: { id: string; name: string } }>(page, context, `/projects/${primaryProject.key}/tracking-values`, {
    kind: 'severity', code: 'e2e-major', name: `E2E Major ${runId}`, rank: 10,
  })).data

  await context.close()
  for (const role of ['reporter', 'projectAdmin', 'developer', 'qa', 'outsider'] as const) {
    const session = await login(browser, users[role].email, role)
    await session.context.close()
  }

  writeFileSync(resolve(authDir, 'e2e-data.json'), JSON.stringify({
    runId,
    primaryProject,
    isolatedProject,
    users,
    tracking: { priority, severity },
  }, null, 2))
})
