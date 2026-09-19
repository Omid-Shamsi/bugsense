import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect, test, type Browser, type BrowserContext, type Page } from '@playwright/test'

const here = dirname(fileURLToPath(import.meta.url))
const frontendRoot = resolve(here, '../..')
const authDir = resolve(frontendRoot, 'playwright/.auth')
const evidencePath = resolve(frontendRoot, 'tests/fixtures/e2e-evidence.txt')
const evidenceName = 'e2e-evidence.txt'

type SessionName = 'reporter' | 'projectAdmin' | 'developer' | 'qa' | 'outsider' | 'systemAdmin'

interface E2EData {
  runId: string
  primaryProject: { id: string; key: string; name: string }
  isolatedProject: { id: string; key: string; name: string }
  users: Record<'reporter' | 'projectAdmin' | 'developer' | 'qa' | 'outsider', { id: string; email: string; display_name: string }>
  tracking: {
    priority: { id: string; name: string }
    severity: { id: string; name: string }
  }
}

let data: E2EData
let lifecycleBugId = ''
let lifecycleTitle = ''
let relationshipTargetId = ''
let rejectionBugId = ''

async function rolePage(browser: Browser, role: SessionName): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ storageState: resolve(authDir, `${role}.json`) })
  const page = await context.newPage()
  page.on('pageerror', (error) => console.error(`[browser:${role}] ${error.message}`))
  page.on('console', (message) => {
    if (['error', 'warning'].includes(message.type())) console.error(`[browser:${role}] ${message.text()}`)
  })
  return { context, page }
}

async function createBug(page: Page, projectId: string, title: string, description: string): Promise<string> {
  await page.goto('/bugs/new')
  await expect(page.getByRole('heading', { name: 'ثبت باگ' })).toBeVisible()
  await page.getByLabel('پروژه', { exact: true }).selectOption(projectId)
  await page.getByLabel('عنوان').fill(title)
  await page.getByLabel('توضیحات', { exact: true }).fill(description)
  await page.getByRole('button', { name: 'ثبت باگ' }).click()
  await expect(page).toHaveURL(/\/bugs\/BUG-\d+$/)
  const publicIdHeading = page.getByRole('heading', { level: 1, name: /^BUG-\d+$/ })
  await expect(publicIdHeading).toBeVisible()
  const publicId = (await publicIdHeading.textContent())?.trim() || ''
  expect(publicId).toMatch(/^BUG-\d+$/)
  return publicId
}

async function expectStatus(page: Page, status: string): Promise<void> {
  await expect(page.getByText(status, { exact: true }).first()).toBeVisible()
}

async function triageAndAssign(page: Page, publicId: string): Promise<void> {
  await page.goto(`/bugs/${publicId}`)
  await page.getByRole('button', { name: 'شروع بررسی' }).click()
  await expectStatus(page, 'در حال بررسی')
  await page.getByLabel('توسعه‌دهنده واجد شرایط').selectOption(data.users.developer.id)
  await page.getByRole('button', { name: 'تخصیص توسعه‌دهنده' }).click()
  await expectStatus(page, 'تخصیص داده‌شده')
}

async function developAndResolve(page: Page, publicId: string, suffix: string): Promise<void> {
  await page.goto('/work/developer')
  await expect(page.getByRole('heading', { name: 'کارهای تخصیص‌یافته' })).toBeVisible()
  await page.getByRole('link', { name: publicId, exact: true }).click()
  await page.getByRole('button', { name: 'شروع کار' }).click()
  await expectStatus(page, 'در حال انجام')

  await page.getByLabel(/گزارش پیشرفت/).fill(`E2E progress ${suffix}`)
  await page.getByRole('button', { name: 'ثبت پیشرفت' }).click()
  await expect(page.getByLabel(/گزارش پیشرفت/)).toHaveValue('')

  await page.getByLabel(/توضیحات/).fill(`Fixed through the real Developer UI ${suffix}`)
  await page.getByLabel(/دستورالعمل QA/).fill(`Verify the E2E behavior ${suffix}`)
  await page.getByRole('button', { name: 'ثبت به‌عنوان رفع‌شده' }).click()
  await expectStatus(page, 'بررسی QA')
  await expect(page.getByText('در انتظار QA', { exact: true })).toBeVisible()
}

test.describe('BugSense essential core journeys', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(() => {
    data = JSON.parse(readFileSync(resolve(authDir, 'e2e-data.json'), 'utf8')) as E2EData
    lifecycleTitle = `E2E lifecycle ${data.runId}`
  })

  test('Reporter creates, validates, edits, and manages private evidence', async ({ browser }) => {
    const { context, page } = await rolePage(browser, 'reporter')
    try {
      await page.goto('/bugs/new')
      await page.getByLabel('پروژه', { exact: true }).selectOption(data.primaryProject.id)
      await page.getByRole('button', { name: 'ثبت باگ' }).click()
      await expect(page.getByText('The title field is required.', { exact: true })).toBeVisible()
      await expect(page.getByText('The description field is required.', { exact: true })).toBeVisible()

      await page.getByLabel('عنوان').fill(lifecycleTitle)
      await page.getByLabel('توضیحات', { exact: true }).fill('A deterministic browser-created report for the complete role lifecycle.')
      await page.getByLabel('مراحل بازتولید').fill('1. Open BugSense\n2. Follow the lifecycle')
      await page.getByLabel('نتیجه مورد انتظار').fill('The lifecycle completes through independent QA.')
      await page.getByLabel('نتیجه فعلی').fill('The report begins in Submitted.')
      await page.getByLabel('محیط', { exact: true }).fill('Chromium Playwright')
      await page.getByLabel('سکو').fill('Windows')
      await page.getByLabel('نسخه برنامه').fill('T060')
      await page.getByRole('button', { name: 'ثبت باگ' }).click()
      await expect(page).toHaveURL(/\/bugs\/BUG-\d+$/)
      const publicIdHeading = page.getByRole('heading', { level: 1, name: /^BUG-\d+$/ })
      await expect(publicIdHeading).toBeVisible()
      lifecycleBugId = (await publicIdHeading.textContent())?.trim() || ''
      expect(lifecycleBugId).toMatch(/^BUG-\d+$/)
      await expectStatus(page, 'ارسال‌شده')

      const editedTitle = `${lifecycleTitle} edited`
      await page.getByRole('button', { name: 'ویرایش گزارش' }).click()
      await page.getByLabel('عنوان').fill(editedTitle)
      await page.getByRole('button', { name: 'ذخیره تغییرات' }).click()
      await expect(page.locator('header').getByText(editedTitle, { exact: true })).toBeVisible()
      lifecycleTitle = editedTitle

      const attachmentPanel = page.locator('section').filter({ has: page.getByRole('heading', { name: 'پیوست‌ها' }) })
      await attachmentPanel.getByLabel('افزودن مدرک').setInputFiles(evidencePath)
      await attachmentPanel.getByRole('button', { name: 'بارگذاری مدرک' }).click()
      await expect(attachmentPanel.getByText(evidenceName, { exact: true })).toBeVisible()

      const downloadEvent = page.waitForEvent('download')
      await attachmentPanel.getByRole('button', { name: 'دانلود' }).click()
      const download = await downloadEvent
      expect(download.suggestedFilename()).toBe(evidenceName)
      const downloadedPath = await download.path()
      expect(downloadedPath).not.toBeNull()
      expect(readFileSync(downloadedPath!, 'utf8')).toContain('BugSense T060 evidence fixture.')

      const attachmentRow = attachmentPanel.getByRole('listitem').filter({ hasText: evidenceName })
      await attachmentRow.getByRole('button', { name: 'حذف' }).click()
      await expect(attachmentPanel.getByText(evidenceName, { exact: true })).toHaveCount(0)
      await expect(attachmentPanel.getByText('هنوز مدرکی پیوست نشده است.', { exact: true })).toBeVisible()

      relationshipTargetId = await createBug(
        page,
        data.primaryProject.id,
        `E2E relationship target ${data.runId}`,
        'A same-project target for one representative Related-to relationship.',
      )
      rejectionBugId = await createBug(
        page,
        data.primaryProject.id,
        `E2E rejection branch ${data.runId}`,
        'A second report used to verify QA rejection and Developer resume.',
      )
    } finally {
      await context.close()
    }
  })

  test('project Admin triages, assigns, manages a relationship, inspects history, and configures tracking', async ({ browser }) => {
    const { context, page } = await rolePage(browser, 'projectAdmin')
    try {
      await page.goto(`/bugs/${lifecycleBugId}`)
      await page.getByRole('button', { name: 'شروع بررسی' }).click()
      await expectStatus(page, 'در حال بررسی')

      await page.getByLabel('اولویت', { exact: true }).selectOption({ label: data.tracking.priority.name })
      await page.getByRole('button', { name: 'تعیین اولویت' }).click()
      await expect(page.getByText(data.tracking.priority.name, { exact: true }).first()).toBeVisible()
      await page.getByLabel('شدت', { exact: true }).selectOption({ label: data.tracking.severity.name })
      await page.getByRole('button', { name: 'تعیین شدت' }).click()
      await expect(page.getByText(data.tracking.severity.name, { exact: true }).first()).toBeVisible()

      await page.getByLabel('توسعه‌دهنده واجد شرایط').selectOption(data.users.developer.id)
      await page.getByRole('button', { name: 'تخصیص توسعه‌دهنده' }).click()
      await expectStatus(page, 'تخصیص داده‌شده')

      await page.getByLabel('نوع ارتباط').selectOption('related_to')
      await page.getByLabel('شناسه عمومی باگ مقصد').fill(relationshipTargetId)
      await page.getByRole('button', { name: 'ایجاد ارتباط' }).click()
      await expect(page.getByText('ارتباط ایجاد شد.', { exact: true })).toBeVisible()
      const relationshipLink = page.getByRole('link', { name: relationshipTargetId, exact: true })
      const relationshipRow = page.getByRole('listitem').filter({ has: relationshipLink })
      await expect(relationshipRow.getByText('مرتبط با', { exact: true })).toBeVisible()
      await expect(relationshipLink).toBeVisible()
      await relationshipRow.getByRole('button', { name: 'غیرفعال کردن', exact: true }).click()
      await expect(page.getByText('ارتباط غیرفعال شد.', { exact: true })).toBeVisible()
      await expect(page.getByRole('link', { name: relationshipTargetId, exact: true })).toHaveCount(0)

      await expect(page.getByRole('heading', { name: 'تاریخچه فعالیت' })).toBeVisible()
      await expect(page.getByText('ایجاد شد', { exact: true }).first()).toBeVisible()
      await expect(page.getByText('بررسی آغاز شد', { exact: true })).toBeVisible()
      await expect(page.getByText('تخصیص داده شد', { exact: true }).first()).toBeVisible()
      await expect(page.getByText('ارتباط غیرفعال شد', { exact: true })).toBeVisible()
      const sequences = (await page.locator('.activity-sequence').allTextContents()).map((value) => Number(value.replace('#', '')))
      expect(sequences.length).toBeGreaterThan(3)
      expect(sequences).toEqual([...sequences].sort((left, right) => left - right))

      await triageAndAssign(page, rejectionBugId)

      await page.goto(`/admin/projects/${data.primaryProject.key}`)
      await expect(page.getByRole('heading', { name: data.primaryProject.name })).toBeVisible()
      const addTracking = page.getByRole('heading', { name: 'افزودن مقدار قابل تنظیم' }).locator('..')
      const trackingName = `E2E Browser Tag ${data.runId}`
      await addTracking.getByLabel('نوع').selectOption('tag')
      await addTracking.getByLabel('کد').fill(`browser-${data.runId.toLowerCase()}`)
      await addTracking.getByLabel('نام').fill(trackingName)
      await addTracking.getByRole('button', { name: 'افزودن مقدار' }).click()
      await expect(page.getByText(trackingName, { exact: true })).toBeVisible()
    } finally {
      await context.close()
    }
  })

  test('assigned Developer records progress and hands two fixes to QA', async ({ browser }) => {
    const { context, page } = await rolePage(browser, 'developer')
    try {
      await developAndResolve(page, lifecycleBugId, 'approval branch')
      await developAndResolve(page, rejectionBugId, 'rejection branch')
    } finally {
      await context.close()
    }
  })

  test('independent QA approves one resolution and rejects another', async ({ browser }) => {
    const { context, page } = await rolePage(browser, 'qa')
    try {
      await page.goto('/work/qa')
      await expect(page.getByRole('heading', { name: 'صف بررسی' })).toBeVisible()
      await page.getByRole('link', { name: lifecycleBugId, exact: true }).click()
      await page.getByLabel(/توضیحات بررسی/).fill('Independent QA verified the approved branch.')
      await page.getByRole('button', { name: 'تأیید نتیجه رفع' }).click()
      await expectStatus(page, 'بسته‌شده')

      await page.goto('/work/qa')
      await page.getByRole('link', { name: rejectionBugId, exact: true }).click()
      await page.getByRole('radio', { name: /ردشده/ }).check()
      await page.getByLabel(/توضیحات بررسی/).fill('Independent QA reproduced the issue; more development is required.')
      await page.getByRole('button', { name: 'رد نتیجه رفع' }).click()
      await expectStatus(page, 'بازگشایی‌شده')
      await expect(page.getByRole('heading', { name: 'نتیجه رفع رد شد' })).toBeVisible()
    } finally {
      await context.close()
    }
  })

  test('retained Developer resumes a rejected Fixed attempt', async ({ browser }) => {
    const { context, page } = await rolePage(browser, 'developer')
    try {
      await page.goto(`/bugs/${rejectionBugId}`)
      await expectStatus(page, 'بازگشایی‌شده')
      await page.getByRole('button', { name: 'ادامه کار' }).click()
      await expectStatus(page, 'در حال انجام')
      const details = page.getByRole('heading', { name: 'جزئیات' }).locator('..')
      await expect(details.getByText(data.users.developer.display_name, { exact: true })).toBeVisible()
    } finally {
      await context.close()
    }
  })

  test('project isolation, bug search, and dashboard summary use real server results', async ({ browser }) => {
    const outsiderSession = await rolePage(browser, 'outsider')
    let outsiderBugId = ''
    try {
      outsiderBugId = await createBug(
        outsiderSession.page,
        data.isolatedProject.id,
        `E2E isolated ${data.runId}`,
        'This report must remain invisible to the primary-project Reporter.',
      )
    } finally {
      await outsiderSession.context.close()
    }

    const reporterSession = await rolePage(browser, 'reporter')
    try {
      const inaccessibleResponse = reporterSession.page.waitForResponse((response) => (
        response.request().method() === 'GET'
        && response.url().endsWith(`/api/v1/bugs/${outsiderBugId}`)
      ))
      await reporterSession.page.goto(`/bugs/${outsiderBugId}`)
      expect((await inaccessibleResponse).status()).toBe(404)
      await expect(reporterSession.page.getByRole('alert')).toContainText('در دسترس نیست')
      await expect(reporterSession.page.getByText(`E2E isolated ${data.runId}`, { exact: true })).toHaveCount(0)

      await reporterSession.page.goto('/bugs')
      await reporterSession.page.getByLabel('جستجو').fill(lifecycleTitle)
      await reporterSession.page.getByRole('button', { name: 'اعمال فیلترها' }).click()
      await expect(reporterSession.page.getByRole('link', { name: lifecycleBugId, exact: true })).toBeVisible()

      await reporterSession.page.goto('/dashboard')
      await expect(reporterSession.page.getByRole('heading', { name: 'داشبورد', exact: true })).toBeVisible()
      await expect(reporterSession.page.getByRole('heading', { name: 'تعدادها' })).toBeVisible()
      const projectBreakdown = reporterSession.page.getByRole('heading', { name: 'پروژه', exact: true }).locator('..')
      await expect(projectBreakdown.getByText(data.primaryProject.name, { exact: false })).toBeVisible()
    } finally {
      await reporterSession.context.close()
    }
  })
})
