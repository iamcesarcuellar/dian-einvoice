import { expect, test, type Locator, type Page } from '@playwright/test'

// The synthetic demo account of make demo, owner of Demo Comercial SAS, and what it sells: an
// office chair (450.000) and a pack of paper (21.500), both at IVA 19 %.
const DEMO = { email: 'demo@example.com', password: 'password', company: 'Demo Comercial SAS' }
const TOTAL = '$ 561.085'

// The queue workers run the chain and the delivery; a CI runner is slower than a laptop.
const CHAIN_TIMEOUT = 120_000

/** Opens a search select, types in it and takes the option that matches. */
async function choose(page: Page, select: Locator, search: string, option: RegExp): Promise<void> {
  await select.click()
  await select.fill(search)
  await page.getByRole('option', { name: option }).click()
}

test('an invoice from draft to paid: issued, accepted by the DIAN simulator, delivered, its PDF, and its payment', async ({ page }) => {
  await test.step('sign in and work in Demo Comercial SAS', async () => {
    await page.goto('/login')
    await page.getByLabel('Email', { exact: true }).fill(DEMO.email)
    await page.getByLabel('Password', { exact: true }).fill(DEMO.password)
    await page.getByRole('button', { name: 'Sign in' }).click()

    const switcher = page.getByRole('button', { name: 'Switch company' })
    await switcher.click()
    await page.getByRole('menuitem', { name: new RegExp(DEMO.company) }).click()
    await expect(switcher).toContainText(DEMO.company)
  })

  await test.step('write an invoice with two lines; the API calculates its total', async () => {
    await page.goto('/invoices')
    await page.getByRole('link', { name: 'New invoice' }).click()

    const customer = page.getByRole('combobox', { name: 'Customer' })
    await choose(page, customer, 'Cordillera', /Distribuidora Cordillera Plata SAS/)
    await expect(customer).toHaveValue('Distribuidora Cordillera Plata SAS')

    const items = page.getByRole('combobox', { name: 'Item', exact: true })
    await choose(page, items.nth(0), 'chair', /Ergonomic office chair/)
    await page.getByRole('button', { name: 'Add line' }).click()
    await choose(page, items.nth(1), 'paper', /Letter paper, 500 sheets/)
    await expect(items).toHaveCount(2)

    await expect(page.getByText(TOTAL).first()).toBeVisible()
  })

  let invoiceUrl = ''
  let number = ''

  await test.step('issue it', async () => {
    await page.getByRole('button', { name: 'Issue invoice' }).click()
    await page.getByRole('dialog', { name: 'Issue this invoice?' }).getByRole('button', { name: 'Issue invoice' }).click()

    const heading = page.getByRole('heading', { level: 1 })
    await expect(heading).toHaveText(/^SETP\d+$/)
    number = (await heading.textContent()) ?? ''
    invoiceUrl = page.url()
  })

  await test.step('the workers take it to the DIAN simulator, which accepts it, and deliver it by email', async () => {
    // Each step of the progress strip tells its state to screen readers, as in "Accepted (done)".
    const steps = page.getByRole('region', { name: 'Progress of the electronic document' }).getByRole('listitem')
    await expect(steps.filter({ hasText: 'Accepted (done)' })).toBeVisible({ timeout: CHAIN_TIMEOUT })
    await expect(steps.filter({ hasText: 'Delivered (done)' })).toBeVisible({ timeout: CHAIN_TIMEOUT })
  })

  await test.step('open its PDF', async () => {
    const pdf = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/pdf'))
    await page.getByRole('button', { name: 'View PDF' }).click()

    const response = await pdf
    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toContain('application/pdf')
    expect((await response.body()).subarray(0, 5).toString()).toBe('%PDF-')

    const viewer = page.getByTitle(`PDF of invoice ${number}`)
    await expect(viewer).toBeVisible()
    await expect(viewer).toHaveAttribute('src', /^blob:/)
    await page.keyboard.press('Escape')
    await expect(viewer).toBeHidden()
  })

  await test.step('record its payment for the full amount, and see it paid', async () => {
    await page.getByRole('link', { name: 'Record payment' }).click()

    const record = page.getByRole('button', { name: `Record payment of ${TOTAL}` })
    await expect(record).toBeEnabled()
    await record.click()
    await expect(page).toHaveURL(/\/payments\/[0-9a-z]{26}$/i)

    await page.goto(invoiceUrl)
    const header = page.locator('header', { has: page.getByRole('heading', { level: 1, name: number }) })
    await expect(header.getByText('Paid', { exact: true })).toBeVisible({ timeout: 30_000 })
  })
})
