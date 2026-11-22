import { mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import type { Browser, BrowserContext, BrowserContextOptions, Locator, Page } from '@playwright/test'
import { expect, test } from '@playwright/test'

/**
 * make screenshots (scripts/screenshots.sh): the screenshots and the frames of the two GIFs of the README, against the
 * local stack with the synthetic demo data the script has just reloaded, under the story clock (Thursday, November 19,
 * 2026, 3 p.m. in Medellín; SCREENSHOTS_CLOCK_OFFSET seconds from the clock of the machine, the same offset the backend
 * and the DIAN simulator run with). Desktop, 1440×900: the screenshots at twice the density, the frames of the GIFs at
 * once, with a drawn pointer. Any error in the console of the browser, an uncaught exception or an error on screen that
 * the story does not expect fails the run. The files go to SCREENSHOTS_RAW; screenshots/encode.mjs writes docs/assets.
 */

const RAW = process.env.SCREENSHOTS_RAW ?? '/tmp/screenshots'
const OFFSET_SECONDS = Number(process.env.SCREENSHOTS_CLOCK_OFFSET)
const ORIGIN = 'http://localhost:8601'
const DEMO = { email: 'demo@example.com', password: 'password', company: 'Demo Comercial SAS' }
const VIEWPORT = { width: 1440, height: 900 }
// The queue workers run the chain and the delivery; every transmission attempt of the outage waits its delay.
const CHAIN_TIMEOUT = 120_000
const OUTAGE_TIMEOUT = 420_000

interface Listed {
  readonly id: string
  readonly status?: string
  readonly full_number?: string | null
  readonly customer_name?: string | null
  readonly reason_text?: string | null
  readonly payment_status?: string | null
  readonly electronic?: { readonly status?: string } | null
  readonly delivery?: { readonly status?: string } | null
}

/** Errors the browser reports, in every page of the run. */
const problems: string[] = []

function watch(page: Page, name: string): Page {
  const where = () => `${name} ${new URL(page.url()).pathname}`

  page.on('console', (message) => {
    if (message.type() === 'error') {
      problems.push(`${where()}: console error: ${message.text()} ${message.location().url}`.trim())
    }
  })
  page.on('pageerror', (error) => problems.push(`${where()}: uncaught exception: ${error.message}`))

  return page
}

/** A context of the run: the story clock installed before any page opens, and the session of the demo user. */
async function context(browser: Browser, options: BrowserContextOptions): Promise<BrowserContext> {
  const created = await browser.newContext({ baseURL: ORIGIN, viewport: VIEWPORT, locale: 'en-US', timezoneId: 'America/Bogota', ...options })
  await created.clock.install({ time: Date.now() + OFFSET_SECONDS * 1000 })

  return created
}

/**
 * Waits until the page is drawn and still: no request in flight (unless `idle` is false, for a page whose viewer of PDFs
 * keeps its own connection), fonts loaded, no skeleton, no toast, no hover, no focus.
 */
async function settle(page: Page, idle = true): Promise<void> {
  if (idle) {
    await page.waitForLoadState('networkidle')
  }

  await page.evaluate(async () => {
    await document.fonts.ready
  })
  await expect(page.locator('[data-slot="skeleton"]:visible')).toHaveCount(0)
  await expect(page.locator('[data-sonner-toast]')).toHaveCount(0, { timeout: 15_000 })
  await page.mouse.move(900, 28)
  await page.evaluate(() => {
    if (document.activeElement instanceof HTMLElement) {
      document.activeElement.blur()
    }
  })
  await page.waitForTimeout(400)
}

/** Fails on an error the screen shows that the story does not expect: a destructive alert or an error toast. */
async function expectNoErrorOnScreen(page: Page, expectedAlerts = 0): Promise<void> {
  await expect(page.locator('[data-slot="alert"].text-destructive:visible'), `errors on screen at ${page.url()}`).toHaveCount(expectedAlerts)
  await expect(page.locator('[data-sonner-toast][data-type="error"]'), `error toasts at ${page.url()}`).toHaveCount(0)
  expect(problems, 'errors reported by the browser').toEqual([])
}

/** One screenshot of the viewport, at the density of its context. */
async function still(page: Page, name: string, expectedAlerts = 0, idle = true): Promise<void> {
  await settle(page, idle)
  await expectNoErrorOnScreen(page, expectedAlerts)
  await page.screenshot({ path: join(RAW, 'stills', `${name}.png`), animations: 'disabled', caret: 'hide' })
}

/** The frames of a GIF, each shown for its delay; encode.mjs merges frames that do not change. */
class Film {
  private readonly frames: { file: string; delay: number }[] = []
  private readonly directory: string

  constructor(
    private readonly page: Page,
    private readonly name: string,
  ) {
    this.directory = join(RAW, 'films', name)
    mkdirSync(this.directory, { recursive: true })
  }

  /** A frame shown for `delay` ms; the screen may show the errors the story expects there, and no other. */
  async frame(delay: number, expectedAlerts = 0): Promise<void> {
    await expectNoErrorOnScreen(this.page, expectedAlerts)
    const file = join(this.directory, `${String(this.frames.length).padStart(4, '0')}.png`)
    await this.page.screenshot({ path: file, animations: 'disabled', caret: 'initial' })
    this.frames.push({ file, delay })
  }

  /** Moves the pointer to the element in a few steps, shows it there, and clicks. */
  async click(target: Locator, delay = 350, expectedAlerts = 0): Promise<void> {
    await target.scrollIntoViewIfNeeded()
    const box = await target.boundingBox()
    expect(box, 'the element to click is on screen').not.toBeNull()

    if (box !== null) {
      await this.page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 8 })
    }

    await this.frame(delay, expectedAlerts)
    await target.click()
  }

  /** Types into the focused field, a frame every few characters. */
  async type(text: string, every = 2, delay = 110): Promise<void> {
    for (let index = 0; index < text.length; index++) {
      await this.page.keyboard.type(text[index] ?? '')

      if ((index + 1) % every === 0 || index === text.length - 1) {
        await this.frame(delay)
      }
    }
  }

  save(): void {
    writeFileSync(join(RAW, 'films', `${this.name}.json`), JSON.stringify(this.frames, null, 2))
  }
}

/** A pointer drawn into the pages of the GIFs: a headless browser draws none. */
function pointer(): void {
  if (window.top !== window) {
    return
  }

  const draw = () => {
    if (document.getElementById('screenshots-pointer')) {
      return
    }

    const element = document.createElement('div')
    element.id = 'screenshots-pointer'
    element.setAttribute('aria-hidden', 'true')
    element.style.cssText = 'position:fixed;left:0;top:0;z-index:2147483647;pointer-events:none;transform:translate(-64px,-64px);'
    element.innerHTML =
      '<svg width="22" height="22" viewBox="0 0 24 24"><path d="M4 2l15 11.5-6.6.9 3.8 7.3-2.7 1.4-3.8-7.4L4 20z" fill="#111827" stroke="#ffffff" stroke-width="1.5" stroke-linejoin="round"/></svg>'
    document.documentElement.appendChild(element)
    document.addEventListener('mousemove', (event) => (element.style.transform = `translate(${event.clientX - 3}px, ${event.clientY - 2}px)`), true)
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', draw)
  } else {
    draw()
  }
}

/** The records of the company from its API, with the session of the page. */
async function list(page: Page, resource: string): Promise<Listed[]> {
  const companyId = await page.evaluate(() => localStorage.getItem('dian-einvoice.current-company'))
  const answer = await page.request.get(`/api/v1/${resource}`, { headers: { Accept: 'application/json', Referer: `${ORIGIN}/`, 'X-Company-Id': companyId ?? '' } })
  expect(answer.ok(), resource).toBe(true)

  return ((await answer.json()) as { data: Listed[] }).data
}

async function find(page: Page, resource: string, accept: (item: Listed) => boolean): Promise<Listed> {
  const found = (await list(page, resource)).find(accept)
  expect(found, `the demo data has the record of ${resource} the story shows`).toBeDefined()

  return found as Listed
}

/** Opens a search select, types in it and takes the option that matches. */
async function choose(page: Page, select: Locator, search: string, option: RegExp, film?: Film): Promise<void> {
  if (film) {
    await film.click(select)
    await film.type(search, 3)
    await film.click(page.getByRole('option', { name: option }), 450)
  } else {
    await select.click()
    await select.fill(search)
    await page.getByRole('option', { name: option }).click()
  }
}

/** Picks an option of a select (SelectInput). */
async function pick(page: Page, select: Locator, option: string, film?: Film): Promise<void> {
  if (film) {
    await film.click(select)
    await film.frame(500)
    await film.click(page.getByRole('option', { name: option, exact: true }), 400)
  } else {
    await select.click()
    await page.getByRole('option', { name: option, exact: true }).click()
  }
}

/**
 * Signs the demo user in through the API, as the web app does (the CSRF cookie of Sanctum, then the sign-in), so no
 * page is ever drawn without a session, and makes Demo Comercial SAS the company of every page of the context.
 */
async function signIn(context: BrowserContext): Promise<string> {
  const headers = { Accept: 'application/json', Referer: `${ORIGIN}/` }
  expect((await context.request.get('/sanctum/csrf-cookie', { headers })).ok(), 'the CSRF cookie').toBe(true)
  const token = (await context.cookies(ORIGIN)).find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? ''
  const answer = await context.request.post('/api/v1/auth/login', { headers: { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(token) }, data: { email: DEMO.email, password: DEMO.password } })
  expect(answer.ok(), 'the sign-in of the demo user').toBe(true)
  const companies = ((await answer.json()) as { data: { companies: { id: string; name: string }[] } }).data.companies
  const company = companies.find((candidate) => candidate.name === DEMO.company)
  expect(company, DEMO.company).toBeDefined()

  return (company as { id: string }).id
}

/** Every page of the context opens in the company. */
async function inCompany(context: BrowserContext, company: string): Promise<void> {
  await context.addInitScript(
    ({ id, origin }) => {
      // The pages of the web app only, not the frames inside them, such as the viewer of a PDF.
      if (window.top === window && location.origin === origin) {
        localStorage.setItem('dian-einvoice.current-company', id)
      }
    },
    { id: company, origin: ORIGIN },
  )
}

/** The strip of the electronic document: each step tells its state, as in "Accepted (done)". */
function step(page: Page, text: string): Locator {
  return page.getByRole('region', { name: 'Progress of the electronic document' }).getByRole('listitem').filter({ hasText: text })
}

/** Scrolls a card of the page, found by its title, to just below the sticky header of the app. */
async function scrollToSection(page: Page, title: string): Promise<void> {
  const heading = page.locator('[data-slot="card-title"]').getByText(title, { exact: true })
  await expect(heading).toHaveCount(1)
  await heading.evaluate((element) => {
    const card = element.closest('[data-slot="card"]') ?? element
    ;(card as HTMLElement).style.scrollMarginTop = '80px'
    card.scrollIntoView({ block: 'start' })
  })
  await expect(heading).toBeInViewport()
}

/** Sets the failure mode of the simulator for the company, from its card in Settings → DIAN. */
async function setSimulatorMode(page: Page, mode: string, film?: Film): Promise<void> {
  await page.goto('/settings/dian')
  await scrollToSection(page, 'Local simulator (development tool)')

  if (film) {
    await settle(page)
    await film.frame(1100)
  }

  await pick(page, page.getByRole('combobox', { name: 'Failure mode' }), mode, film)
  const apply = page.getByRole('button', { name: 'Apply', exact: true })

  if (film) {
    await film.click(apply, 400)
  } else {
    await apply.click()
  }

  await expect(page.getByText('Mode applied.')).toBeVisible()
}

test('the screenshots and the GIFs of the README', async ({ browser }) => {
  expect(Number.isSafeInteger(OFFSET_SECONDS), 'SCREENSHOTS_CLOCK_OFFSET, set by make screenshots').toBe(true)
  mkdirSync(join(RAW, 'stills'), { recursive: true })

  // Light, at twice the density: the screenshots.
  const light = await context(browser, { deviceScaleFactor: 2, colorScheme: 'light' })
  const company = await signIn(light)
  const session = await light.storageState()

  // The same session in the dark theme, and at the density of the GIFs with a drawn pointer.
  const dark = await context(browser, { deviceScaleFactor: 2, colorScheme: 'dark', storageState: session })
  const filming = await context(browser, { deviceScaleFactor: 1, colorScheme: 'light', storageState: session })
  await filming.addInitScript(pointer)

  for (const each of [light, dark, filming]) {
    await inCompany(each, company)
  }

  const page = watch(await light.newPage(), 'light')
  const night = watch(await dark.newPage(), 'dark')
  const camera = watch(await filming.newPage(), 'film')

  await test.step('dashboard and invoices', async () => {
    await page.goto('/')
    await expect(page.locator('[data-chart]').first()).toBeVisible()
    await still(page, 'dashboard')

    await page.goto('/invoices')
    await expect(page.getByRole('link', { name: 'New invoice' })).toBeVisible()
    await still(page, 'invoices')
  })

  await test.step('the editor with its live totals', async () => {
    await page.goto('/invoices/new')
    await choose(page, page.getByRole('combobox', { name: 'Customer' }), 'Cordillera', /Distribuidora Cordillera Plata SAS/)
    const items = page.getByRole('combobox', { name: 'Item', exact: true })
    await choose(page, items.nth(0), 'chair', /Ergonomic office chair/)
    await page.getByLabel('Quantity').nth(0).fill('2')
    await page.getByRole('button', { name: 'Add line' }).click()
    await choose(page, items.nth(1), 'paper', /Letter paper, 500 sheets/)
    await page.getByLabel('Quantity').nth(1).fill('10')
    await page.getByRole('button', { name: 'Add line' }).click()
    await choose(page, items.nth(2), 'coffee', /Ground coffee, 500 g/)
    await page.getByLabel('Quantity').nth(2).fill('6')
    await expect(items).toHaveCount(3)
    await scrollToSection(page, 'Lines')
    await still(page, 'invoice-editor')
  })

  let issued = ''

  await test.step('GIF: an invoice from the editor to accepted, and its PDF', async () => {
    const film = new Film(camera, 'issue-invoice')
    await camera.goto('/invoices')
    await settle(camera)
    await film.frame(1100)
    await film.click(camera.getByRole('link', { name: 'New invoice' }))
    await expect(camera.getByRole('combobox', { name: 'Customer' })).toBeVisible()
    await settle(camera)
    await film.frame(500)

    await choose(camera, camera.getByRole('combobox', { name: 'Customer' }), 'Cordillera', /Distribuidora Cordillera Plata SAS/, film)
    const items = camera.getByRole('combobox', { name: 'Item', exact: true })
    await choose(camera, items.nth(0), 'chair', /Ergonomic office chair/, film)
    await film.frame(600)
    await film.click(camera.getByRole('button', { name: 'Add line' }))
    await choose(camera, items.nth(1), 'paper', /Letter paper, 500 sheets/, film)
    await expect(camera.getByText('$ 561.085').first()).toBeVisible()
    await film.frame(1000)

    await film.click(camera.getByRole('button', { name: 'Issue invoice' }))
    const dialog = camera.getByRole('dialog', { name: 'Issue this invoice?' })
    await expect(dialog).toBeVisible()
    await film.frame(900)
    await film.click(dialog.getByRole('button', { name: 'Issue invoice' }))

    const heading = camera.getByRole('heading', { level: 1 })
    await expect(heading).toHaveText(/^SETP\d+$/)
    issued = new URL(camera.url()).pathname
    await camera.mouse.move(900, 28, { steps: 6 })

    // The workers take it through the chain: a frame whenever the strip changes, until it is delivered.
    for (const done of ['XML (done)', 'Signed (done)', 'Sent (done)', 'Accepted (done)', 'Delivered (done)']) {
      await expect(step(camera, done)).toBeVisible({ timeout: CHAIN_TIMEOUT })
      await film.frame(done.startsWith('Delivered') ? 1500 : 450)
    }

    await film.click(camera.getByRole('button', { name: 'View PDF' }))
    const viewer = camera.getByTitle(/^PDF of invoice SETP\d+$/)
    await expect(viewer).toBeVisible()
    await expect(viewer).toHaveAttribute('src', /^blob:/)
    await camera.mouse.move(900, 28, { steps: 4 })
    await camera.waitForTimeout(1500)
    await film.frame(3000)
    await camera.keyboard.press('Escape')
    film.save()
  })

  await test.step('an accepted invoice: the answer of the DIAN simulator, its delivery, its PDF', async () => {
    const invoice = await find(
      page,
      'invoices?per_page=100',
      (item) => item.customer_name === 'Distribuidora Cordillera Plata SAS' && item.payment_status === 'paid' && item.delivery?.status === 'delivered',
    )
    await page.goto(`/invoices/${invoice.id}`)
    await expect(step(page, 'Delivered (done)')).toBeVisible()
    await scrollToSection(page, 'Electronic document')
    await still(page, 'invoice-accepted')

    await page.evaluate(() => window.scrollTo(0, 0))
    await page.getByRole('button', { name: 'View PDF' }).click()
    const viewer = page.getByTitle(`PDF of invoice ${invoice.full_number}`)
    await expect(viewer).toHaveAttribute('src', /^blob:/)
    await page.waitForTimeout(2500)
    await still(page, 'invoice-pdf', 0, false)
    await page.keyboard.press('Escape')

    await night.goto(`/invoices/${invoice.id}`)
    await expect(step(night, 'Delivered (done)')).toBeVisible()
    await still(night, 'invoice-dark')
    await night.goto('/')
    await expect(night.locator('[data-chart]').first()).toBeVisible()
    await still(night, 'dashboard-dark')
  })

  await test.step('a credit note, the receivables, the developers and the numbering', async () => {
    const note = await find(page, 'credit-notes?per_page=50', (item) => (item.reason_text ?? '').includes('arrived damaged'))
    await page.goto(`/invoices/credit-notes/${note.id}`)
    await expect(step(page, 'Delivered (done)')).toBeVisible()
    await still(page, 'credit-note')

    await page.goto('/payments/receivables')
    await still(page, 'receivables')

    await page.goto('/settings/developers')
    await still(page, 'developers')

    await page.goto('/settings/numbering')
    await still(page, 'numbering')
  })

  await test.step('the command palette', async () => {
    await page.goto('/')
    await expect(page.locator('[data-chart]').first()).toBeVisible()
    await page.getByRole('button', { name: 'Open the command palette' }).click()
    const palette = page.getByRole('dialog')
    await expect(palette).toBeVisible()
    await page.keyboard.type('Cordillera')
    await expect(palette.getByRole('option').first()).toBeVisible()
    await page.waitForLoadState('networkidle')
    await page.mouse.move(900, 28)
    await page.waitForTimeout(400)
    await expectNoErrorOnScreen(page)
    await page.screenshot({ path: join(RAW, 'stills', 'command-palette.png'), animations: 'disabled', caret: 'hide' })
    await page.keyboard.press('Escape')
  })

  await test.step('GIF: the DIAN down, the invoice that fails, the retry and the acceptance', async () => {
    const film = new Film(camera, 'dian-outage')
    await setSimulatorMode(camera, 'Error 500', film)
    await film.frame(900)

    // The draft of the demo for Cordillera Plata, issued while the simulator answers HTTP 500.
    const draft = await find(camera, 'invoices?status=draft&per_page=50', (item) => item.customer_name === 'Distribuidora Cordillera Plata SAS')
    await camera.goto(`/invoices/${draft.id}`)
    await settle(camera)
    await film.frame(900)
    await film.click(camera.getByRole('button', { name: 'Issue invoice' }))
    const dialog = camera.getByRole('dialog', { name: 'Issue this invoice?' })
    await expect(dialog).toBeVisible()
    await film.frame(700)
    await film.click(dialog.getByRole('button', { name: 'Issue invoice' }))
    await expect(camera.getByRole('heading', { level: 1 })).toHaveText(/^SETP\d+$/)
    await camera.mouse.move(900, 28, { steps: 6 })
    await film.frame(900)

    // Settings → DIAN as the failures pile up: the test certificate that signs, the mode of the simulator, and the first
    // attempts of this invoice, answered with HTTP 500. In this mode the simulator answers before it reads the
    // document, so its activity names the file it was sent: the three newest requests are those attempts.
    await page.goto('/settings/dian')
    const newest = page.locator('[data-slot="table-body"] tr')

    for (const index of [0, 1, 2]) {
      await expect(newest.nth(index)).toContainText('error_500', { timeout: CHAIN_TIMEOUT })
    }

    await page.getByText('Serial number', { exact: true }).evaluate((element) => {
      // Right below the sticky header, so the lines above it hide behind it whole.
      ;(element as HTMLElement).style.scrollMarginTop = '66px'
      element.scrollIntoView({ block: 'start' })
    })
    await still(page, 'dian-settings')

    // Every attempt fails; a frame each time the strip tells something new, until the transmission gives up.
    const strip = camera.getByRole('region', { name: 'Progress of the electronic document' })
    const failed = camera.getByText('The transmission failed.', { exact: true })
    const deadline = Date.now() + OUTAGE_TIMEOUT
    let told = await strip.innerText()

    while (!(await failed.isVisible()) && Date.now() < deadline) {
      await camera.waitForTimeout(1_000)
      const now = await strip.innerText()

      if (now !== told) {
        told = now
        await film.frame(1100, await failed.count())
      }
    }

    await expect(failed).toBeVisible()
    await scrollToSection(camera, 'Electronic document')
    await film.frame(1800, 1)

    // The DIAN is back: the mode goes back to normal, and Retry now takes the invoice through.
    await setSimulatorMode(camera, 'Normal', film)
    await film.frame(700)
    await camera.goto(`/invoices/${draft.id}`)
    await scrollToSection(camera, 'Electronic document')
    await settle(camera)
    await film.frame(700, 1)
    await film.click(camera.getByRole('button', { name: 'Retry now' }), 350, 1)
    await camera.mouse.move(900, 28, { steps: 6 })
    await expect(camera.getByText(/^Accepted by the local simulator on/)).toBeVisible({ timeout: CHAIN_TIMEOUT })
    await film.frame(1200)
    await camera.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
    await film.frame(3500)
    film.save()

    await page.goto(`/invoices/${draft.id}`)
    await expect(step(page, 'Delivered (done)')).toBeVisible({ timeout: CHAIN_TIMEOUT })
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
    await still(page, 'invoice-recovered')
  })

  expect(issued, 'the invoice of the first GIF was issued').not.toBe('')
  expect(problems, 'errors reported by the browser').toEqual([])
  await Promise.all([light.close(), dark.close(), filming.close()])
})
