import type { Page } from '@playwright/test'
import type { Language } from './messages'
import { t } from './messages'
import { expect, test } from './policy'
import { routesOf } from './routes'

// Nothing of the web app spills out of its box, in English and in Spanish, on three desktop windows (1280×720, 1440×900
// and 1920×1080), with the synthetic demo data of make demo (or make cloud-demo behind the edge): every route the router
// declares, the dashboard included, read from the running app; a rejected and a failed invoice; the payment editor with a
// customer's open invoices; and the main dialogs, as the tour of make cloud-e2e opens them. The measure is the DOM's, one
// element at a time:
// - the page never scrolls sideways;
// - no visible element sticks out of the box that holds it: a card, a dialog, a table container or a panel (a sheet, the
//   sidebar, a menu, a list, or anything drawn with a border on both sides);
// - no visible element is cut by its box, or by any container that clips, where the reader cannot scroll to it;
// - every dialog fits the window.
// Not a fault: a text cut on purpose with an ellipsis or a line clamp, and a zone that scrolls sideways itself, such as a
// wide table or the XML of a document, as long as that zone fits in its box. On top of that, every list fits a window of
// 1440×900 or more without scrolling sideways: a name that does not fit is cut with an ellipsis before a column is pushed
// out of view.

// The synthetic demo account of make demo, owner of Demo Comercial SAS.
const DEMO = { email: 'demo@example.com', password: 'password', company: 'Demo Comercial SAS' }
const ORIGIN = 'http://localhost:8601'

/** The windows of the check, from the smallest: a laptop, the size of the screenshots, a full HD monitor. */
const WINDOWS = [
  { width: 1280, height: 720 },
  { width: 1440, height: 900 },
  { width: 1920, height: 1080 },
] as const

/** The elements a fault may name and the check lets pass, each with its reason. */
const EXCEPTIONS: readonly { readonly selector: string; readonly reason: string }[] = [
  {
    selector: 'aside > div.pointer-events-none[aria-hidden="true"]',
    reason: 'The two circles of the brand panel on the sign-in screens are decoration, hidden from assistive technology: the panel cuts them on purpose.',
  },
  {
    selector: '[data-slot="sidebar-rail"]',
    reason: 'The sidebar rail is an invisible handle that straddles the sidebar’s edge by design: a click on it folds the sidebar.',
  },
]

/** The pages that show at least one list: each must have drawn its data before it is measured. */
const LISTS = new Set([
  '/invoices',
  '/invoices/credit-notes',
  '/invoices/debit-notes',
  '/invoices/:id',
  '/invoices/credit-notes/:id',
  '/payments',
  '/payments/receivables',
  '/payments/:id',
  '/contacts',
  '/items',
  '/settings/taxes',
  '/settings/withholdings',
  '/settings/numbering',
  '/settings/dian',
  '/settings/team',
  '/settings/developers',
])

/** The buttons of each route that open its main dialogs (message keys); each opens, and closes with Escape. */
const DIALOGS: Readonly<Record<string, readonly string[]>> = {
  '/items': ['items.new'],
  '/settings/taxes': ['taxes.new'],
  '/settings/withholdings': ['withholdings.new'],
  '/settings/numbering': ['numbering.new'],
  '/settings/dian': ['settings.dian.certificate.upload'],
  '/settings/team': ['team.invite.action'],
  '/settings/developers': ['developers.keys.new', 'developers.webhooks.new'],
  '/invoices/:id': ['invoices.delivery.viewPdf', 'invoices.dian.viewXml', 'invoices.dian.viewResponse', 'invoices.delivery.sendAgain'],
  '/payments/:id': ['payments.void.open'],
}

interface Listed {
  readonly id: string
  readonly [field: string]: unknown
}

/** The state of a document's electronic chain or delivery, as the lists of the API give it. */
function stateOf(value: unknown): string | undefined {
  return (value as { status?: string } | null)?.status
}

/**
 * What spills out of its box in the page as it is drawn now, one line per fault. A fault names the outermost element
 * only: what it holds spills with it.
 */
async function spills(page: Page, exceptions: readonly string[]): Promise<string[]> {
  return page.evaluate((excepted) => {
    // Subpixel rounding.
    const SLACK = 1
    const BOXES = [
      '[data-slot="card"]',
      '[data-slot="table-container"]',
      '[role="dialog"]',
      '[role="alertdialog"]',
      '[data-slot="sidebar-inner"]',
      '[data-slot="dropdown-menu-content"]',
      '[data-slot="select-content"]',
      '[data-slot="tooltip-content"]',
      '[role="menu"]',
      '[role="listbox"]',
    ].join(', ')
    const styles = new Map<Element, CSSStyleDeclaration>()
    const style = (element: Element): CSSStyleDeclaration => {
      let found = styles.get(element)

      if (found === undefined) {
        found = getComputedStyle(element)
        styles.set(element, found)
      }

      return found
    }
    const px = (value: string): number => Number.parseFloat(value) || 0
    const bordered = (element: Element): boolean => {
      const own = style(element)

      return px(own.borderLeftWidth) > 0 && px(own.borderRightWidth) > 0 && own.borderLeftStyle !== 'none' && own.borderRightStyle !== 'none'
    }
    const isBox = (element: Element): boolean => element.matches(BOXES) || bordered(element)
    const text = (element: Element | null | undefined): string => (element?.textContent ?? '').replace(/\s+/g, ' ').trim()
    const short = (value: string): string => (value.length > 48 ? `${value.slice(0, 47)}…` : value)

    /** An element as a reader of the report finds it: its tag, slot or role, first classes and first words. */
    const describe = (element: Element): string => {
      const slot = element.getAttribute('data-slot') ?? element.getAttribute('role')
      const classes = (element.getAttribute('class') ?? '')
        .split(/\s+/)
        .filter((name) => name !== '' && !/[:[\]/()]/.test(name))
        .slice(0, 3)
      const words = short(text(element))

      return `${element.tagName.toLowerCase()}${slot ? `[${slot}]` : ''}${classes.map((name) => `.${name}`).join('')}${words ? ` “${words}”` : ''}`
    }

    /** A box by its kind and title: the card "Electronic documents", the dialog "XML of SETP990000001". */
    const nameBox = (box: Element): string => {
      const title = text(box.querySelector('[data-slot="card-title"], [data-slot="dialog-title"], [data-slot="sheet-title"]'))

      if (box.matches('[data-slot="card"]')) {
        return `the card${title ? ` “${short(title)}”` : ''}`
      }

      if (box.matches('[role="dialog"], [role="alertdialog"]')) {
        return `the dialog${title ? ` “${short(title)}”` : ''}`
      }

      if (box.matches('[data-slot="table-container"]')) {
        return `the table container of ${short([...box.querySelectorAll('th')].map((header) => text(header)).filter(Boolean).join(', '))}`
      }

      return `the panel ${describe(box)}`
    }

    /** The card or dialog around an element, for the report. */
    const around = (element: Element): string => {
      const box = element.parentElement?.closest('[data-slot="card"], [role="dialog"], [role="alertdialog"]')

      return box ? `, in ${nameBox(box)}` : ''
    }

    /** Whether the reader sees the element: drawn, with a size, not transparent; inside a drawing, the drawing counts. */
    const shown = (element: Element): boolean => {
      if (element instanceof SVGElement && !(element instanceof SVGSVGElement)) {
        return false
      }

      const rect = element.getBoundingClientRect()

      return rect.width >= 2 && rect.height >= 2 && element.checkVisibility({ opacityProperty: true, visibilityProperty: true })
    }

    type Holder = { readonly element: Element | null; readonly clips: boolean } | 'scrolls' | 'trimmed'

    /** What holds an element along one axis: the nearest container that scrolls, clips, or is a box; else the page. */
    const holderOf = (element: Element, axis: 'x' | 'y'): Holder => {
      if (style(element).position === 'fixed') {
        return { element: null, clips: false }
      }

      for (let parent = element.parentElement; parent !== null && parent !== document.body; parent = parent.parentElement) {
        const own = style(parent)
        const overflow = axis === 'x' ? own.overflowX : own.overflowY

        if (overflow === 'auto' || overflow === 'scroll') {
          return 'scrolls'
        }

        if (overflow === 'hidden' || overflow === 'clip') {
          return own.textOverflow === 'ellipsis' || own.getPropertyValue('-webkit-line-clamp') !== 'none' ? 'trimmed' : { element: parent, clips: true }
        }

        if (isBox(parent)) {
          return { element: parent, clips: false }
        }

        if (own.position === 'fixed') {
          break
        }
      }

      return { element: null, clips: false }
    }

    interface Fault {
      readonly holder: Element | null
      readonly line: string
    }

    const faults = new Map<Element, Fault[]>()
    const width = document.documentElement.clientWidth

    for (const element of document.body.querySelectorAll('*')) {
      if (!shown(element) || excepted.some((selector) => element.matches(selector))) {
        continue
      }

      const rect = element.getBoundingClientRect()

      for (const axis of ['x', 'y'] as const) {
        const holder = holderOf(element, axis)

        if (holder === 'scrolls' || holder === 'trimmed' || (holder.element === null && axis === 'y')) {
          continue
        }

        // A box that clips cuts at its padding edge; one that does not is left at its border edge.
        let edges = { left: 0, right: width, top: 0, bottom: 0 }

        if (holder.element !== null) {
          const box = holder.element.getBoundingClientRect()
          const own = style(holder.element)
          const inset = holder.clips
            ? { left: px(own.borderLeftWidth), right: px(own.borderRightWidth), top: px(own.borderTopWidth), bottom: px(own.borderBottomWidth) }
            : { left: 0, right: 0, top: 0, bottom: 0 }
          edges = { left: box.left + inset.left, right: box.right - inset.right, top: box.top + inset.top, bottom: box.bottom - inset.bottom }
        }

        const sides =
          axis === 'x'
            ? [
                ['left', edges.left - rect.left],
                ['right', rect.right - edges.right],
              ]
            : [
                ['top', edges.top - rect.top],
                ['bottom', rect.bottom - edges.bottom],
              ]

        for (const [side, by] of sides as [string, number][]) {
          if (by <= SLACK) {
            continue
          }

          const amount = `${Math.round(by)} px on the ${side}`
          const line =
            holder.element === null
              ? `${describe(element)} goes past the window’s edge by ${amount}`
              : holder.clips
                ? `${describe(element)} is cut by ${isBox(holder.element) ? nameBox(holder.element) : describe(holder.element)} by ${amount}, with no way to scroll to it${around(holder.element)}`
                : `${describe(element)} sticks out of ${nameBox(holder.element)} by ${amount}${around(holder.element)}`
          faults.set(element, [...(faults.get(element) ?? []), { holder: holder.element, line }])
        }
      }
    }

    // The outermost element of each fault: one whose container spills out of the same holder already says it.
    const lines: string[] = []

    for (const [element, own] of faults) {
      for (const fault of own) {
        let inherited = false

        for (let parent = element.parentElement; parent !== null && parent !== fault.holder; parent = parent.parentElement) {
          if ((faults.get(parent) ?? []).some((other) => other.holder === fault.holder)) {
            inherited = true
            break
          }
        }

        if (!inherited) {
          lines.push(fault.line)
        }
      }
    }

    // The page itself, and every dialog against the window.
    const sideways = document.documentElement.scrollWidth - document.documentElement.clientWidth

    if (sideways > SLACK) {
      lines.push(`the page scrolls sideways by ${sideways} px`)
    }

    for (const dialog of document.querySelectorAll('[role="dialog"], [role="alertdialog"]')) {
      const rect = dialog.getBoundingClientRect()

      if (!shown(dialog)) {
        continue
      }

      if (rect.left < -SLACK || rect.top < -SLACK || rect.right > window.innerWidth + SLACK || rect.bottom > window.innerHeight + SLACK) {
        lines.push(`${nameBox(dialog)} (${Math.round(rect.width)}×${Math.round(rect.height)}) does not fit the window`)
      }
    }

    return [...new Set(lines)]
  }, exceptions)
}

/** Each list of the page that scrolls sideways in its box, by its header, with how many pixels it overflows. */
async function wideLists(page: Page): Promise<string[]> {
  return page.evaluate(() =>
    [...document.querySelectorAll<HTMLElement>('[data-slot="table-container"]')]
      .filter((container) => container.offsetParent !== null && container.scrollWidth > container.clientWidth + 1)
      .map((container) => {
        const widths = [...container.querySelectorAll('th')].map(
          (header) => `${header.textContent?.trim() || '·'}=${Math.round(header.getBoundingClientRect().width)}`,
        )

        return `the list of ${widths.join(' ')} scrolls sideways by ${container.scrollWidth - container.clientWidth} px in its box of ${container.clientWidth}`
      }),
  )
}

/** Waits until the page is drawn and still: its fonts loaded, its finite animations over, and two frames after. */
async function settle(page: Page): Promise<void> {
  await page.evaluate(async () => {
    const frame = () => new Promise<void>((resolve) => requestAnimationFrame(() => resolve()))
    await document.fonts.ready
    await frame()
    const running = document
      .getAnimations()
      .filter((animation) => animation.playState === 'running' && Number.isFinite(Number(animation.effect?.getComputedTiming().endTime)))
    await Promise.race([Promise.all(running.map((animation) => animation.finished.catch(() => undefined))), new Promise((resolve) => setTimeout(resolve, 3_000))])
    await frame()
    await frame()
  })
}

for (const [language, locale] of [
  ['en', 'en-US'],
  ['es', 'es-CO'],
] as [Language, string][]) {
  test.describe(locale, () => {
    test.use({ locale, viewport: WINDOWS[0] })

    test(`nothing spills out of its box at 1280×720, 1440×900 or 1920×1080 (${language})`, async ({ page }) => {
      test.setTimeout(900_000)
      const say = (key: string, values: Record<string, string | number> = {}) => t(language, key, values)
      const faults: string[] = []
      const measured: string[] = []

      // The requests of the page in flight: a dialog or a list loads its data after the page has loaded.
      let inFlight = 0
      let lastRequest = Date.now()
      page.on('request', () => {
        inFlight += 1
        lastRequest = Date.now()
      })

      for (const event of ['requestfinished', 'requestfailed'] as const) {
        page.on(event, () => {
          inFlight = Math.max(0, inFlight - 1)
          lastRequest = Date.now()
        })
      }

      /** Waits until no request is in flight for a moment and no skeleton is left. */
      const quiet = async (): Promise<void> => {
        await expect.poll(() => inFlight === 0 && Date.now() - lastRequest > 400, { timeout: 30_000, intervals: [100] }).toBe(true)
        await expect(page.locator('[data-slot="skeleton"]:visible')).toHaveCount(0)
      }

      /** Measures the page as it is now on each window, from the smallest, and goes back to the first. */
      const measure = async (where: string): Promise<void> => {
        for (const size of WINDOWS) {
          await page.setViewportSize(size)
          await settle(page)
          const at = `${where} at ${size.width}×${size.height} (${language})`
          faults.push(...(await spills(page, EXCEPTIONS.map((exception) => exception.selector))).map((fault) => `${at}: ${fault}`))

          if (size.width >= 1440) {
            faults.push(...(await wideLists(page)).map((fault) => `${at}: ${fault}`))
          }
        }

        await page.setViewportSize(WINDOWS[0])
        await settle(page)
        measured.push(where)
      }

      /** Opens a page, waits until it has drawn its data, and measures it. */
      const visit = async (path: string, route = path): Promise<void> => {
        await page.goto(path)
        await page.waitForLoadState('networkidle')
        await quiet()
        expect(new URL(page.url()).pathname, `${path} was drawn, not redirected`).toBe(path)

        if (LISTS.has(route)) {
          await expect(page.locator('[data-slot="table-container"]:visible').first(), `${path} shows a list`).toBeVisible()
        }

        await measure(route === path ? path : `${route} (${path})`)
      }

      /** Opens a dialog with its button, measures it, and closes it with Escape. False when the button is disabled. */
      const dialog = async (route: string, label: string): Promise<boolean> => {
        const button = page.getByRole('button', { name: label, exact: true }).first()
        await expect(button, label).toBeVisible()

        if (!(await button.isEnabled())) {
          return false
        }

        await button.click()
        const open = page.getByRole('dialog').or(page.getByRole('alertdialog')).first()
        await expect(open, label).toBeVisible()
        await quiet()
        await expect(open.getByText(say('common.loading'), { exact: true })).toHaveCount(0)
        await measure(`${route} “${label}”`)
        await page.keyboard.press('Escape')
        await expect(open, label).toBeHidden()

        return true
      }

      // The routes of the router, and the pages of visitors without a session first.
      await page.goto('/login')
      await page.waitForLoadState('networkidle')
      const routes = await routesOf(page)
      expect(routes.length).toBeGreaterThan(25)

      for (const route of routes.filter((candidate) => candidate.guest || candidate.public)) {
        await visit(route.path.replace(/:[a-z]+/i, 'a-link-of-the-check-that-is-not-a-token'), route.path)
      }

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
        const response = await page.request.get(`/api/v1/${resource}?per_page=100`, {
          headers: { Accept: 'application/json', Referer: `${ORIGIN}/`, 'X-Company-Id': companyId ?? '' },
        })
        expect(response.ok(), resource).toBe(true)
        const found = ((await response.json()) as { data: Listed[] }).data.find(accept)
        expect(found, `the demo data has ${resource} for this check`).toBeDefined()

        return (found as Listed).id
      }

      // The value of each parameter: the fullest object of the demo data. The invoice was accepted and delivered, has
      // payments and a credit note, so its page shows every table it can have and every dialog opens.
      const parameters: Readonly<Record<string, () => Promise<string>>> = {
        '/invoices/:id': () =>
          first(
            'invoices',
            (item) =>
              item.payment_status !== 'unpaid' &&
              item.balance_status === 'partially_credited' &&
              stateOf(item.electronic) === 'accepted' &&
              stateOf(item.delivery) === 'delivered',
          ),
        '/invoices/credit-notes/:id': () => first('credit-notes', (item) => item.status === 'issued'),
        '/invoices/debit-notes/:id': () => first('debit-notes'),
        '/payments/:id': () => first('payments', (item) => item.status !== 'voided'),
        '/contacts/:id': () => first('contacts'),
      }
      const signedIn = routes.filter((route) => !route.guest && !route.public)
      expect(
        signedIn.filter((route) => route.path.includes(':') && parameters[route.path] === undefined).map((route) => route.path),
        'every route with a parameter has a value in the check',
      ).toEqual([])

      for (const route of signedIn) {
        const parameter = parameters[route.path]
        await visit(parameter === undefined ? route.path : route.path.replace(/:[a-z]+/i, await parameter()), route.path)

        for (const key of DIALOGS[route.path] ?? []) {
          await dialog(route.path, say(key))
        }
      }

      // The page of an invoice the DIAN simulator rejected, and of one whose chain failed: their reasons are the longest.
      for (const state of ['rejected', 'failed']) {
        const id = await first('invoices', (item) => stateOf(item.electronic) === state)
        await visit(`/invoices/${id}`, '/invoices/:id')
      }

      // The open invoices of a customer in the payment editor.
      await page.goto('/payments/new')
      await quiet()
      const customer = page.getByRole('combobox', { name: say('payments.editor.customer') })
      await customer.click()
      await customer.fill('Tamarindo')
      await page.getByRole('option', { name: /Comercializadora Tamarindo Azul SAS/ }).click()
      await expect(page.getByRole('button', { name: new RegExp(`^${say('payments.editor.payInFullFor', { number: 'SETP\\d+' })}$`) }).first()).toBeVisible()
      await quiet()
      await measure('/payments/new (a customer’s open invoices)')

      // On the dashboard: the command palette with results, About and a new company.
      await page.goto('/')
      await quiet()
      await page.getByRole('button', { name: say('palette.open') }).click()
      await expect(page.getByRole('dialog')).toBeVisible()
      await page.keyboard.type('Cordillera')
      await quiet()
      await measure('/ (the command palette)')
      await page.keyboard.press('Escape')
      await expect(page.getByRole('dialog')).toBeHidden()

      await page.getByRole('button', { name: say('user.menu') }).click()
      await page.getByRole('menuitem', { name: say('user.about') }).click()
      await expect(page.getByRole('dialog')).toBeVisible()
      await quiet()
      await measure('/ (About)')
      await page.keyboard.press('Escape')
      await expect(page.getByRole('dialog')).toBeHidden()

      await page.getByRole('button', { name: say('companies.switcher.trigger') }).click()
      await page.getByRole('menuitem', { name: say('companies.new.action') }).click()
      await expect(page.getByRole('dialog')).toBeVisible()
      await quiet()
      await measure('/ (New company)')
      await page.keyboard.press('Escape')
      await expect(page.getByRole('dialog')).toBeHidden()

      console.log(`The check measured ${measured.length} pages and dialogs in ${language}, each at ${WINDOWS.length} window sizes.`)
      expect(measured.length).toBeGreaterThan(routes.length + 12)
      expect(faults, 'what spills out of its box').toEqual([])
    })
  })
}
