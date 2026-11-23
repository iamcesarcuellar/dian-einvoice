import { mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import type { Browser, BrowserContext, BrowserContextOptions, Locator, Page } from '@playwright/test'
import { expect, test } from '@playwright/test'

/**
 * make screenshots (scripts/screenshots.sh): the screenshots and the frames of the two GIFs of the README, against the
 * local stack with the synthetic demo data the script has just reloaded, under the story clock (Thursday, November 19,
 * 2026, 3 p.m. in Medellín; SCREENSHOTS_CLOCK_OFFSET seconds from the clock of the machine, the same offset the backend
 * and the DIAN simulator run with). Desktop, 1440×900, in a browser set to British English (en-GB), so the native date
 * field of the editor reads day, month and year in the order of the app's own dates. The screenshots at twice the
 * density, 1440 px wide and at most 900 px high: each starts with the page header or a card and ends with a card or a
 * table row, shortened when the screen ends in the middle of one. The frames of the GIFs at once, with a drawn pointer.
 * Any error in the console of the browser, an uncaught exception or an error on screen that the story does not expect
 * fails the run. The files go to SCREENSHOTS_RAW; screenshots/encode.mjs writes docs/assets.
 */

const RAW = process.env.SCREENSHOTS_RAW ?? '/tmp/screenshots'
const OFFSET_SECONDS = Number(process.env.SCREENSHOTS_CLOCK_OFFSET)
const ORIGIN = 'http://localhost:8601'
const DEMO = { email: 'demo@example.com', password: 'password', company: 'Demo Comercial SAS' }
const VIEWPORT = { width: 1440, height: 900 }
/** The bar at the top of every page, which stays there when the page scrolls. */
const APP_BAR = 56
/** The page's background kept between the bar and the first card of a screenshot, or below its last card: less than the 24 px between two cards. */
const MARGIN = 16
/**
 * Where a screenshot that starts with a card puts it: 20 px below the bar, so the card above, 24 px up, hides behind
 * the bar whole with its shadow, and the card starts within a few pixels of the totals panel, which sticks 80 px from
 * the top.
 */
const CARD_TOP = APP_BAR + 20
/** Longer than the 250 ms a search field waits after the last key before it asks the API. */
const SEARCH_PAUSE = 300
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
  const created = await browser.newContext({ baseURL: ORIGIN, viewport: VIEWPORT, locale: 'en-GB', timezoneId: 'America/Bogota', ...options })
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

/**
 * Runs in the page: where a screenshot of it can start and end. A clean line goes through no card, row, menu item, text
 * or control; it may go through a card only between two rows of its table or of its timeline (an ordered list). For
 * the top, the line right below the bar must be clean in the page itself (the sidebar runs beside the bar); for the
 * bottom, the lowest clean line up to the limit, the end of a card with a margin of background when it fits.
 */
function cleanLines(input: { edge: 'top' | 'bottom'; line: number; limit: number; margin: number }): string[] | number {
  const BLOCKS = [
    '[data-slot="card"]',
    '[data-slot="table-container"]',
    '[data-slot="alert"]',
    '[data-sidebar="menu-item"]',
    '[data-sidebar="group-label"]',
    '[data-sidebar="header"]',
    '[data-sidebar="footer"]',
    'footer',
    'section',
    'h1',
    'h2',
    'h3',
    'p',
    'li',
    'tr',
    'dt',
    'dd',
    'button',
    'a',
    'input',
    'label',
    'svg',
    'iframe',
  ].join(', ')
  const CONTAINERS = '[data-slot="card"], [data-slot="table-container"]'
  const ROWS = 'tr, ol > li'

  const boxes = (from: number, to: number) =>
    [...document.querySelectorAll<HTMLElement>(BLOCKS)]
      // The rail of the sidebar is an invisible button as high as the screen: it is no content to cut.
      .filter((element) => !element.matches('[data-sidebar="rail"]'))
      .filter((element) => input.edge === 'bottom' || element.closest('[data-slot="sidebar"]') === null)
      .map((element) => ({ element, box: element.getBoundingClientRect() }))
      .filter(({ box }) => box.width > 0 && box.height > 0 && box.bottom > from && box.top < to)
  const crosses = (box: DOMRect, line: number) => box.top < line - 0.5 && box.bottom > line + 0.5
  const name = (element: HTMLElement) => `${element.tagName.toLowerCase()} "${(element.textContent ?? '').trim().replace(/\s+/g, ' ').slice(0, 60)}"`

  // What a cut at the line would go through: nothing when the line is clean.
  const cutAt = (line: number) =>
    boxes(line - 1, line + 1)
      .filter(({ element, box }) => {
        if (!crosses(box, line)) {
          return false
        }

        if (!element.matches(CONTAINERS)) {
          return true
        }

        // A card or a table goes on past the line between two of its rows, none of them cut.
        const rows = [...element.querySelectorAll<HTMLElement>(ROWS)].map((row) => row.getBoundingClientRect())

        return !(rows.some((row) => row.bottom <= line + 1) && rows.some((row) => row.top >= line - 1) && rows.every((row) => !crosses(row, line)))
      })
      .map(({ element }) => name(element))

  if (input.edge === 'top') {
    return cutAt(input.line)
  }

  const candidates = new Set([input.limit])

  for (const { element, box } of boxes(0, input.limit)) {
    if (box.bottom > input.limit) {
      continue
    }

    if (element.matches(CONTAINERS)) {
      candidates.add(Math.min(input.limit, Math.ceil(box.bottom) + input.margin))
      candidates.add(Math.ceil(box.bottom))
    } else if (element.matches(ROWS)) {
      candidates.add(Math.ceil(box.bottom))
    }
  }

  return [...candidates].sort((a, b) => b - a).find((line) => cutAt(line).length === 0) ?? input.limit
}

/** The height of a screenshot of the page that ends with a card or a row, at most the height of the viewport. */
async function cleanHeight(page: Page): Promise<number> {
  return (await page.evaluate(cleanLines, { edge: 'bottom', line: 0, limit: VIEWPORT.height, margin: MARGIN })) as number
}

/** Fails when the page, right below the bar, starts in the middle of something: the bar hides what is above it whole. */
async function expectCleanTop(page: Page): Promise<void> {
  const cut = (await page.evaluate(cleanLines, { edge: 'top', line: APP_BAR, limit: VIEWPORT.height, margin: MARGIN })) as string[]
  expect(cut, `what ${new URL(page.url()).pathname} starts with, below the bar`).toEqual([])
}

/** One screenshot at the density of its context: the viewport, cut at its lowest clean line; a dialog takes the whole viewport. */
async function still(page: Page, name: string, options: { expectedAlerts?: number; idle?: boolean; dialog?: boolean } = {}): Promise<void> {
  await settle(page, options.idle ?? true)
  await expectNoErrorOnScreen(page, options.expectedAlerts ?? 0)

  if (!options.dialog) {
    await expectCleanTop(page)
  }

  const height = options.dialog ? VIEWPORT.height : await cleanHeight(page)
  await page.screenshot({
    path: join(RAW, 'stills', `${name}.png`),
    animations: 'disabled',
    caret: 'hide',
    clip: { x: 0, y: 0, width: VIEWPORT.width, height },
  })
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

  /**
   * A frame shown for `delay` ms; the screen may show the errors the story expects there, and no other. A total being
   * calculated is never filmed: a page with a totals panel waits until it shows the totals of the lines on screen.
   */
  async frame(delay: number, expectedAlerts = 0): Promise<void> {
    await expect(totalsPanel(this.page).getByRole('status')).toHaveCount(0)
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

  /** Types into the focused search field, a frame every few characters once its list holds the options of what is typed. */
  async type(text: string, every = 3, delay = 110): Promise<void> {
    for (let index = 0; index < text.length; index++) {
      await this.page.keyboard.type(text[index] ?? '')

      if ((index + 1) % every === 0 || index === text.length - 1) {
        await listShowsTyped(this.page)
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

/**
 * In the frames of the GIFs only, the backdrop of a dialog is a flat dark shade instead of the app's blur: once the
 * frames are cut down to the 256 colours of a GIF, a blur comes out in blotches. The app itself and the PNG
 * screenshots keep the blur.
 */
function flatBackdrops(): void {
  if (window.top !== window) {
    return
  }

  const add = () => {
    const style = document.createElement('style')
    style.textContent =
      '[data-slot="dialog-overlay"], [data-slot="sheet-overlay"] { backdrop-filter: none !important; background-color: rgb(0 0 0 / 0.35) !important; }'
    document.head.appendChild(style)
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', add)
  } else {
    add()
  }
}

/** The records of the company from its API, with the session of the page. */
async function list(page: Page, resource: string): Promise<Listed[]> {
  const companyId = await page.evaluate(() => localStorage.getItem('dian-einvoice.current-company'))
  const answer = await page.request.get(`/api/v1/${resource}`, {
    headers: { Accept: 'application/json', Referer: `${ORIGIN}/`, 'X-Company-Id': companyId ?? '' },
  })
  expect(answer.ok(), resource).toBe(true)

  return ((await answer.json()) as { data: Listed[] }).data
}

async function find(page: Page, resource: string, accept: (item: Listed) => boolean): Promise<Listed> {
  const found = (await list(page, resource)).find(accept)
  expect(found, `the demo data has the record of ${resource} the story shows`).toBeDefined()

  return found as Listed
}

/**
 * Waits until the open search list holds the options of the text typed so far: past the pause the field waits for,
 * the list is no longer busy (aria-busy, while its options are on their way).
 */
async function listShowsTyped(page: Page): Promise<void> {
  await page.waitForTimeout(SEARCH_PAUSE)
  await expect(page.locator('[role="listbox"][aria-busy="true"]')).toHaveCount(0)
}

/** Opens a search select, types in it, waits for the list to narrow to what matches, and takes the option. */
async function choose(page: Page, select: Locator, search: string, option: RegExp, film?: Film): Promise<void> {
  const target = page.getByRole('option', { name: option })

  if (film) {
    await film.click(select)
    await film.type(search)
  } else {
    await select.click()
    await select.fill(search)
    await listShowsTyped(page)
  }

  await expect(target).toBeVisible()
  // Every option left says what was typed: the list is the narrowed one before the choice.
  await expect.poll(async () => (await page.getByRole('option').allInnerTexts()).every((text) => text.toLowerCase().includes(search.toLowerCase()))).toBe(true)

  if (film) {
    await film.frame(900)
    await film.click(target, 450)
  } else {
    await target.click()
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

/** The totals panel of an invoice or a note, on the pages that have one. */
function totalsPanel(page: Page): Locator {
  return page.locator('[data-slot="card"]').filter({ has: page.locator('[data-slot="card-title"]', { hasText: /^Totals$/ }) })
}

/** The totals panel shows the totals of the lines on screen: nothing being calculated, and the total given (or any but zero). */
async function totalsShow(page: Page, total?: string): Promise<void> {
  const panel = totalsPanel(page)
  const amount = panel
    .locator('dl > div')
    .filter({ has: page.locator('dt', { hasText: /^Total$/ }) })
    .locator('dd')

  await expect(panel.getByRole('status')).toHaveCount(0)

  if (total === undefined) {
    await expect(amount).not.toHaveText('$ 0,00')
  } else {
    await expect(amount).toHaveText(total)
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
  const answer = await context.request.post('/api/v1/auth/login', {
    headers: { ...headers, 'X-XSRF-TOKEN': decodeURIComponent(token) },
    data: { email: DEMO.email, password: DEMO.password },
  })
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

/** The card of the page with this title. */
async function card(page: Page, title: string): Promise<Locator> {
  const heading = page.locator('[data-slot="card-title"]').getByText(title, { exact: true })
  await expect(heading).toHaveCount(1)

  return page.locator('[data-slot="card"]').filter({ has: heading })
}

/** Scrolls a card of the page, found by its title, to just below the bar: a screenshot that starts with it. */
async function scrollToSection(page: Page, title: string): Promise<void> {
  const section = await card(page, title)
  await section.evaluate((element, top) => {
    ;(element as HTMLElement).style.scrollMarginTop = `${top}px`
    element.scrollIntoView({ block: 'start' })
  }, CARD_TOP)
  await expect(section).toBeInViewport()
}

/**
 * Scrolls a card so the screen shows its end: a card that fits starts right below the bar; a taller one, such as a long
 * timeline, ends at the bottom of the viewport, and the first of its rows the bar does not hide starts whole, right
 * below the bar.
 */
async function scrollToEndOf(page: Page, title: string): Promise<void> {
  const section = await card(page, title)
  await section.evaluate(
    (element, { height, bar, margin, top }) => {
      window.scrollBy(0, element.getBoundingClientRect().bottom - (height - margin))

      if (element.getBoundingClientRect().top >= top) {
        window.scrollBy(0, element.getBoundingClientRect().top - top)

        return
      }

      const first = [...element.querySelectorAll('tr, ol > li')].find((row) => row.getBoundingClientRect().top >= bar)

      if (first) {
        window.scrollBy(0, first.getBoundingClientRect().top - (bar + margin))
      }
    },
    { height: VIEWPORT.height, bar: APP_BAR, margin: MARGIN, top: CARD_TOP },
  )
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

  // The same session in the dark theme, and at the density of the GIFs with a drawn pointer and flat backdrops.
  const dark = await context(browser, { deviceScaleFactor: 2, colorScheme: 'dark', storageState: session })
  const filming = await context(browser, { deviceScaleFactor: 1, colorScheme: 'light', storageState: session })
  await filming.addInitScript(pointer)
  await filming.addInitScript(flatBackdrops)

  for (const each of [light, dark, filming]) {
    await inCompany(each, company)
  }

  const page = watch(await light.newPage(), 'light')
  const night = watch(await dark.newPage(), 'dark')
  const camera = watch(await filming.newPage(), 'film')

  await test.step('the dashboard in both themes, one after the other with the same figures, and the invoices', async () => {
    await page.goto('/')
    await expect(page.locator('[data-chart]').first()).toBeVisible()
    await still(page, 'dashboard')

    await night.goto('/')
    await expect(night.locator('[data-chart]').first()).toBeVisible()
    await still(night, 'dashboard-dark')

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
    // 2 chairs and 10 packs of paper at IVA 19 %, 6 packs of coffee at IVA 5 %.
    await totalsShow(page, '$ 1.445.920,00')
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
    // An office chair at IVA 19 %.
    await totalsShow(camera, '$ 535.500,00')
    await film.frame(900)
    await film.click(camera.getByRole('button', { name: 'Add line' }))
    await choose(camera, items.nth(1), 'paper', /Letter paper, 500 sheets/, film)
    // And a pack of paper, also at IVA 19 %.
    await totalsShow(camera, '$ 561.085,00')
    await film.frame(1400)

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
    await expect(viewer).toHaveAttribute('src', /^blob:.*#navpanes=0/)
    await camera.mouse.move(900, 28, { steps: 4 })
    await camera.waitForTimeout(1500)
    await film.frame(3000)
    await camera.keyboard.press('Escape')
    film.save()
  })

  await test.step("an accepted invoice: the DIAN simulator's response, its delivery, its PDF", async () => {
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
    await expect(viewer).toHaveAttribute('src', /^blob:.*#navpanes=0/)
    await page.waitForTimeout(2500)
    await still(page, 'invoice-pdf', { idle: false, dialog: true })
    await page.keyboard.press('Escape')

    await night.goto(`/invoices/${invoice.id}`)
    await expect(step(night, 'Delivered (done)')).toBeVisible()
    await still(night, 'invoice-dark')
  })

  await test.step('a credit note, the receivables, the developers and the numbering', async () => {
    const note = await find(page, 'credit-notes?per_page=50', (item) => (item.reason_text ?? '').includes('arrived damaged'))
    await page.goto(`/invoices/credit-notes/${note.id}`)
    await expect(step(page, 'Delivered (done)')).toBeVisible()
    await still(page, 'credit-note')

    await page.goto('/payments/receivables')
    await still(page, 'receivables')

    // The API keys and, below them, the subscription to the local receiver with its last delivery.
    await page.goto('/settings/developers')
    await scrollToSection(page, 'API keys')
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
    await listShowsTyped(page)
    await expect(palette.getByRole('option').first()).toBeVisible()
    await page.mouse.move(900, 28)
    await page.waitForTimeout(400)
    await expectNoErrorOnScreen(page)
    // A dialog over the page: the whole viewport.
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
    await totalsShow(camera)
    await film.frame(900)
    await film.click(camera.getByRole('button', { name: 'Issue invoice' }))
    const dialog = camera.getByRole('dialog', { name: 'Issue this invoice?' })
    await expect(dialog).toBeVisible()
    await film.frame(700)
    await film.click(dialog.getByRole('button', { name: 'Issue invoice' }))
    await expect(camera.getByRole('heading', { level: 1 })).toHaveText(/^SETP\d+$/)
    await camera.mouse.move(900, 28, { steps: 6 })
    await film.frame(900)

    // Settings → DIAN as the failures pile up: the mode of the simulator and the first attempts of this invoice,
    // answered with HTTP 500. In this mode the simulator answers before it reads the document, so its activity names the
    // file it was sent: the three newest requests are those attempts.
    await page.goto('/settings/dian')
    const newest = page.locator('[data-slot="table-body"] tr')

    for (const index of [0, 1, 2]) {
      await expect(newest.nth(index)).toContainText('error_500', { timeout: CHAIN_TIMEOUT })
    }

    await scrollToSection(page, 'Local simulator (development tool)')
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

    // Accepted and delivered: the six steps of the strip done, then the acceptance, then the end of the timeline. The
    // cards of its payments and balance appear once it is accepted, so each frame scrolls to what it shows.
    await expect(step(camera, 'Delivered (done)')).toBeVisible({ timeout: CHAIN_TIMEOUT })
    await camera.evaluate(() => window.scrollTo(0, 0))
    await settle(camera)
    await film.frame(2000)
    await scrollToSection(camera, 'Electronic document')
    await expect(camera.getByText(/^Accepted by the local simulator on/)).toBeInViewport()
    await film.frame(2200)
    await scrollToEndOf(camera, 'Timeline')
    await film.frame(3500)
    film.save()

    await page.goto(`/invoices/${draft.id}`)
    await expect(step(page, 'Delivered (done)')).toBeVisible({ timeout: CHAIN_TIMEOUT })
    await scrollToEndOf(page, 'Timeline')
    await still(page, 'invoice-recovered')
  })

  expect(issued, 'the invoice of the first GIF was issued').not.toBe('')
  expect(problems, 'errors reported by the browser').toEqual([])
  await Promise.all([light.close(), dark.close(), filming.close()])
})
