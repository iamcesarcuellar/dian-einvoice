import type { APIRequestContext, Locator, Page } from '@playwright/test'
import { expect, test } from './policy'
import { t } from './messages'

// The web app in Spanish, as a browser set to Colombian Spanish opens it the first time: sign in, set the company's
// documents to Spanish in Settings → Company, issue an invoice, and see that its PDF and the email to the customer are
// in Spanish. Every text the test clicks or reads comes from the web app's Spanish messages (es.ts). Against the local
// stack the email is read from the local mailbox; behind the edge (make cloud-e2e), from the emails the emulator's SES
// took. The company's document language goes back to what it was at the end.
test.use({ locale: 'es-CO' })

const DEMO = { email: 'demo@example.com', password: 'password', company: 'Demo Comercial SAS' }
const ORIGIN = 'http://localhost:8601'
const CUSTOMER = { search: 'Cordillera', name: 'Distribuidora Cordillera Plata SAS', email: 'billing@cordillera-plata.example' }
// An office chair (450.000) and a pack of paper (21.500), both at IVA 19 %: the same total in both languages.
const TOTAL = '$ 561.085,00'
const CHAIN_TIMEOUT = 120_000

/** Opens a search select, types in it and takes the option that matches. */
async function choose(page: Page, select: Locator, search: string, option: string): Promise<void> {
  await select.click()
  await select.fill(search)
  await page.getByRole('option', { name: option }).click()
}

/** A request of the API with the session of the page and the company. */
function api(
  request: APIRequestContext,
  company: string,
): { get: (path: string) => Promise<unknown>; patch: (path: string, data: unknown, token: string) => Promise<void> } {
  const headers = { Accept: 'application/json', Referer: `${ORIGIN}/`, 'X-Company-Id': company }

  return {
    get: async (path) => {
      const response = await request.get(`/api/v1/${path}`, { headers })
      expect(response.ok(), path).toBe(true)

      return ((await response.json()) as { data: unknown }).data
    },
    patch: async (path, data, token) => {
      const response = await request.patch(`/api/v1/${path}`, { headers: { ...headers, 'X-XSRF-TOKEN': token }, data })
      expect(response.ok(), path).toBe(true)
    },
  }
}

/** A text string of the PDF's information dictionary, such as /Title: dompdf writes it in UTF-16BE, its parentheses escaped. */
function pdfInfo(pdf: Buffer, name: string): string {
  const raw = pdf.toString('latin1')
  const start = raw.indexOf(`/${name} (`)

  if (start === -1) {
    return ''
  }

  let bytes = ''

  for (let index = start + name.length + 3; index < raw.length; index++) {
    const char = raw[index]

    if (char === '\\') {
      const next = raw[++index]
      bytes += next === 'r' ? '\r' : next === 'n' ? '\n' : (next ?? '')
    } else if (char === ')') {
      break
    } else {
      bytes += char
    }
  }

  const utf16 = Buffer.from(bytes, 'latin1')

  return utf16[0] === 0xfe && utf16[1] === 0xff ? utf16.subarray(2).swap16().toString('utf16le') : bytes
}

/** The text part of a raw MIME email, decoded from quoted-printable or base64. */
function textOf(mime: string): string {
  const part = mime.split(/\r?\n--/).find((section) => /Content-Type:\s*text\/plain/i.test(section)) ?? mime
  const [head = '', ...rest] = part.split(/\r?\n\r?\n/)
  const body = rest.join('\n\n')

  if (/Content-Transfer-Encoding:\s*base64/i.test(head)) {
    return Buffer.from(body.replace(/\s+/g, ''), 'base64').toString('utf8')
  }

  if (/Content-Transfer-Encoding:\s*quoted-printable/i.test(head)) {
    const bytes = body.replace(/=\r?\n/g, '').replace(/=([0-9A-F]{2})/gi, (_, hex: string) => String.fromCharCode(Number.parseInt(hex, 16)))

    return Buffer.from(bytes, 'latin1').toString('utf8')
  }

  return body
}

/** The text of the email to the customer about the document: from the local mailbox, or from the emulator's SES behind the edge. */
async function customerEmail(request: APIRequestContext, number: string): Promise<string> {
  if (process.env.EDGE === '1') {
    const response = await request.get('http://floci:4566/_aws/ses')
    expect(response.ok(), "the emulator's list of the emails SES took").toBe(true)
    const messages = ((await response.json()) as { messages?: { RawData?: string; Subject?: string; Body?: { text_part?: string } }[] }).messages ?? []

    for (const message of messages.reverse()) {
      // A raw email (Laravel sends raw MIME through the SES v2 API) is kept in base64, as EmulatedCloud reads it.
      const decoded = Buffer.from(message.RawData ?? '', 'base64').toString('utf8')
      const raw = decoded.includes('Content-Type') ? decoded : (message.RawData ?? '')

      if (raw.includes(number) || (message.Subject ?? '').includes(number)) {
        return raw === '' ? (message.Body?.text_part ?? '') : textOf(raw)
      }
    }

    return ''
  }

  const search = await request.get(`http://mail:8025/api/v1/search?query=${encodeURIComponent(`subject:${number}`)}`)
  expect(search.ok(), 'the search of the local mailbox').toBe(true)
  const id = ((await search.json()) as { messages?: { ID: string }[] }).messages?.[0]?.ID

  if (id === undefined) {
    return ''
  }

  const message = await request.get(`http://mail:8025/api/v1/message/${id}`)
  expect(message.ok(), 'the email in the local mailbox').toBe(true)

  return ((await message.json()) as { Text?: string }).Text ?? ''
}

test('in Spanish: an invoice issued, its PDF and its email to the customer in Spanish', async ({ page }) => {
  const es = (key: string, values: Record<string, string | number> = {}, count?: number) => t('es', key, values, count)
  const step = (label: string) =>
    page
      .getByRole('region', { name: es('invoices.progress.label') })
      .getByRole('listitem')
      .filter({ hasText: `${es(`invoices.progress.${label}`)} (${es('invoices.progress.done')})` })

  await test.step('the browser speaks Colombian Spanish: the web app opens in Spanish', async () => {
    await page.goto('/login')
    await expect(page.locator('html')).toHaveAttribute('lang', 'es-CO')
    await expect(page.getByRole('heading', { level: 1, name: es('auth.login.title') })).toBeVisible()
    await expect(page.getByText(es('environment.label')).first()).toBeVisible()
  })

  await test.step('sign in and work in Demo Comercial SAS', async () => {
    await page.getByLabel(es('fields.email'), { exact: true }).fill(DEMO.email)
    await page.getByLabel(es('fields.password'), { exact: true }).fill(DEMO.password)
    await page.getByRole('button', { name: es('auth.login.submit') }).click()

    const switcher = page.getByRole('button', { name: es('companies.switcher.trigger') })
    await switcher.click()
    await page.getByRole('menuitem', { name: new RegExp(DEMO.company) }).click()
    await expect(switcher).toContainText(DEMO.company)
  })

  const company = (await page.evaluate(() => localStorage.getItem('dian-einvoice.current-company'))) ?? ''
  const client = api(page.request, company)
  const before = ((await client.get(`companies/${company}`)) as { document_language: string }).document_language
  const token = async () => decodeURIComponent((await page.context().cookies(ORIGIN)).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '')

  try {
    await test.step('Settings → Company: the documents in Spanish', async () => {
      await page.goto('/settings/company')
      await page.getByRole('combobox', { name: es('fields.documentLanguage') }).click()
      await page.getByRole('option', { name: es('languages.es'), exact: true }).click()
      await page.getByRole('button', { name: es('common.save') }).click()
      await expect(page.getByText(es('settings.company.saved'))).toBeVisible()
    })

    // The demo sells the same items in both languages, by code; their names are those of the demo data's language.
    const items = (await client.get('items?per_page=100')) as { code: string; name: string }[]
    const named = (code: string) => items.find((item) => item.code === code)?.name ?? code

    let number = ''

    await test.step('write an invoice with two lines and issue it', async () => {
      await page.goto('/invoices')
      await page.getByRole('link', { name: es('invoices.new') }).click()

      const customer = page.getByRole('combobox', { name: es('invoices.editor.customer') })
      await choose(page, customer, CUSTOMER.search, CUSTOMER.name)

      const lines = page.getByRole('combobox', { name: es('invoices.editor.item'), exact: true })
      await choose(page, lines.nth(0), named('P-001').split(' ')[0] ?? '', named('P-001'))
      await page.getByRole('button', { name: es('invoices.editor.addLine') }).click()
      await choose(page, lines.nth(1), named('P-002').split(' ')[0] ?? '', named('P-002'))
      await expect(page.getByText(TOTAL).first()).toBeVisible()

      await page.getByRole('button', { name: es('invoices.editor.issue') }).click()
      await page
        .getByRole('dialog', { name: es('invoices.editor.issueTitle') })
        .getByRole('button', { name: es('invoices.editor.issue') })
        .click()

      const heading = page.getByRole('heading', { level: 1 })
      await expect(heading).toHaveText(/^SETP\d+$/)
      number = (await heading.textContent()) ?? ''
    })

    await test.step('the local simulator accepts it and it is delivered', async () => {
      await expect(step('accepted')).toBeVisible({ timeout: CHAIN_TIMEOUT })
      await expect(step('delivered')).toBeVisible({ timeout: CHAIN_TIMEOUT })
    })

    await test.step('its PDF is in Spanish', async () => {
      const pdf = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/pdf'))
      await page.getByRole('button', { name: es('invoices.delivery.viewPdf') }).click()

      const body = await (await pdf).body()
      expect(body.subarray(0, 5).toString()).toBe('%PDF-')
      expect(pdfInfo(body, 'Title')).toBe(`Factura electrónica de venta ${number} (documento de prueba)`)
      expect(pdfInfo(body, 'Subject')).toContain('Representación gráfica de una factura electrónica de venta')
      await expect(page.getByTitle(es('invoices.delivery.pdfFrame', { number }))).toHaveAttribute('src', /^blob:/)
      await page.keyboard.press('Escape')
    })

    await test.step('its email to the customer is in Spanish', async () => {
      await expect.poll(() => customerEmail(page.request, number), { timeout: 30_000 }).toContain(`te envió la factura electrónica de venta ${number}`)

      const text = await customerEmail(page.request, number)
      expect(text.toUpperCase()).toContain('DOCUMENTO DE PRUEBA')
      expect(text).toContain('No tiene validez tributaria')
    })
  } finally {
    await client.patch(`companies/${company}`, { document_language: before }, await token())
  }
})
