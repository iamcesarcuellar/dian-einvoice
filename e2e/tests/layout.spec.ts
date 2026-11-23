import type { Page } from '@playwright/test'
import type { Language } from './messages'
import { t } from './messages'
import { expect, test } from './policy'

// Every list of the web app fits a desktop screen of 1440×900 without scrolling sideways, in English and in Spanish: each
// table of each page, with the synthetic demo data of make demo (or make cloud-demo behind the edge), the longest names
// and amounts included. A name that does not fit is cut with an ellipsis before a column is pushed out of view.
test.use({ viewport: { width: 1440, height: 900 } })

// The synthetic demo account of make demo, owner of Demo Comercial SAS.
const DEMO = { email: 'demo@example.com', password: 'password', company: 'Demo Comercial SAS' }
const ORIGIN = 'http://localhost:8601'

interface Listed {
  readonly id: string
  readonly [field: string]: unknown
}

/** Each table of the page wider than the box that holds it, by its header, with how many pixels it overflows. */
async function overflowing(page: Page): Promise<string[]> {
  return page.evaluate(() =>
    [...document.querySelectorAll<HTMLElement>('[data-slot="table-container"]')]
      .filter((container) => container.offsetParent !== null && container.scrollWidth > container.clientWidth + 1)
      .map((container) => {
        const headers = [...container.querySelectorAll('th')].map((header) => header.textContent?.trim()).filter(Boolean)

        const widths = [...container.querySelectorAll('th')].map(
          (header) => `${header.textContent?.trim() || '·'}=${Math.round(header.getBoundingClientRect().width)}`,
        )

        return `${location.pathname}: the table of ${headers.join(', ')} overflows by ${container.scrollWidth - container.clientWidth} px [box ${container.clientWidth}: ${widths.join(' ')}]`
      }),
  )
}

/** Opens a page and waits until its lists are drawn with their data. */
async function visit(page: Page, path: string): Promise<void> {
  await page.goto(path)
  await page.waitForLoadState('networkidle')
  await expect(page.locator('[data-slot="skeleton"]:visible')).toHaveCount(0)
}

for (const [language, locale] of [
  ['en', 'en-US'],
  ['es', 'es-CO'],
] as [Language, string][]) {
  test.describe(locale, () => {
    test.use({ locale })

    test(`every list fits a 1440×900 screen without scrolling sideways (${language})`, async ({ page }) => {
      const say = (key: string, values: Record<string, string | number> = {}) => t(language, key, values)

      await test.step('sign in and work in Demo Comercial SAS', async () => {
        await page.goto('/login')
        await page.getByLabel(say('fields.email'), { exact: true }).fill(DEMO.email)
        await page.getByLabel(say('fields.password'), { exact: true }).fill(DEMO.password)
        await page.getByRole('button', { name: say('auth.login.submit') }).click()

        const switcher = page.getByRole('button', { name: say('companies.switcher.trigger') })
        await switcher.click()
        await page.getByRole('menuitem', { name: new RegExp(DEMO.company) }).click()
        await expect(switcher).toContainText(DEMO.company)
      })

      const companyId = await page.evaluate(() => localStorage.getItem('dian-einvoice.current-company'))
      const first = async (resource: string, accept: (item: Listed) => boolean = () => true): Promise<string> => {
        const answer = await page.request.get(`/api/v1/${resource}?per_page=100`, {
          headers: { Accept: 'application/json', Referer: `${ORIGIN}/`, 'X-Company-Id': companyId ?? '' },
        })
        expect(answer.ok(), resource).toBe(true)
        const found = ((await answer.json()) as { data: Listed[] }).data.find(accept)
        expect(found, `the demo data has ${resource} for this test`).toBeDefined()

        return (found as Listed).id
      }

      // An invoice with payments and a credit note, so its page shows every table it can have.
      const invoice = await first('invoices', (item) => item.payment_status !== 'unpaid' && item.balance_status === 'partially_credited')
      const note = await first('credit-notes', (item) => item.status === 'issued')
      const payment = await first('payments', (item) => item.status !== 'voided')

      const pages = [
        '/invoices',
        '/invoices/credit-notes',
        '/invoices/debit-notes',
        `/invoices/${invoice}`,
        `/invoices/credit-notes/${note}`,
        '/payments',
        '/payments/receivables',
        `/payments/${payment}`,
        '/contacts',
        '/items',
        '/settings/taxes',
        '/settings/withholdings',
        '/settings/numbering',
        '/settings/dian',
        '/settings/team',
        '/settings/developers',
      ]
      const problems: string[] = []

      for (const path of pages) {
        await visit(page, path)
        expect(await page.locator('[data-slot="table-container"]:visible').count(), `${path} shows a list`).toBeGreaterThan(0)
        problems.push(...(await overflowing(page)))
      }

      // The open invoices of a customer in the payment editor.
      await visit(page, '/payments/new')
      const customer = page.getByRole('combobox', { name: say('payments.editor.customer') })
      await customer.click()
      await customer.fill('Tamarindo')
      await page.getByRole('option', { name: /Comercializadora Tamarindo Azul SAS/ }).click()
      await expect(page.getByRole('button', { name: new RegExp(`^${say('payments.editor.payInFullFor', { number: 'SETP\\d+' })}$`) }).first()).toBeVisible()
      await page.waitForLoadState('networkidle')
      problems.push(...(await overflowing(page)))

      // The page itself never scrolls sideways either.
      expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(0)
      expect(problems, 'tables wider than their box at 1440×900').toEqual([])
    })
  })
}
