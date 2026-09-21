import { expect, test, type Page } from '@playwright/test'

const project = {
  id: 'project-1',
  key: 'MOBILE',
  name: 'پروژهٔ نمونهٔ گزارش',
  description: null,
  active: true,
}

const currentUser = {
  id: 'user-1',
  email: 'reporter@example.test',
  display_name: 'گزارش‌دهندهٔ نمونه',
  is_system_admin: false,
  memberships: [{ id: 'membership-1', project, roles: ['reporter'], active: true }],
}

const trackingValues = [
  { id: 'category-1', project_id: project.id, kind: 'category', code: 'ui', name: 'رابط کاربری', rank: 10, active: true },
  { id: 'tag-1', project_id: project.id, kind: 'tag', code: 'regression', name: 'بازگشت', rank: 10, active: true },
  { id: 'tag-2', project_id: project.id, kind: 'tag', code: 'mobile', name: 'موبایل', rank: 20, active: true },
]

async function mockReportBugData(page: Page) {
  await page.route('**/api/v1/me', (route) => route.fulfill({ json: { data: currentUser } }))
  await page.route('**/api/v1/projects', (route) => route.fulfill({ json: { data: [project] } }))
  await page.route(`**/api/v1/projects/${project.key}/tracking-values`, (route) => route.fulfill({ json: { data: trackingValues } }))
}

test('Report Bug renders the same logical form at desktop and mobile widths', async ({ page }) => {
  await mockReportBugData(page)
  await page.setViewportSize({ width: 1440, height: 1000 })
  await page.goto('/bugs/new')

  await expect(page.getByLabel('عنوان')).toBeVisible()
  await expect(page.getByLabel('دسته‌بندی')).toBeEnabled()
  await expect(page.getByRole('heading', { name: 'محیط و مدرک' })).toBeVisible()
  await page.screenshot({ path: 'test-results/report-bug-desktop.png', fullPage: true })

  await page.setViewportSize({ width: 390, height: 844 })
  await expect(page.getByLabel('عنوان')).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy()
  await page.screenshot({ path: 'test-results/report-bug-mobile.png', fullPage: true })
})
