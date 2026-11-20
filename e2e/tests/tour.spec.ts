import type { Page } from '@playwright/test'
import { expect, test } from './policy'

// A tour of the whole web app under the Content-Security-Policy of the edge (make cloud-e2e only): every route its
// router declares, read from the running app, each loaded as a deep link through the edge, with the synthetic demo
// data, and on the way its main dialogs, menus, lists, the command palette, the themes and the hover card of a chart.
// The watch of ./policy fails the tour on the first violation the browser reports, in any frame.
test.skip(process.env.EDGE !== '1', 'The policy of the web app is the edge’s: the development server of the local stack sends none.')

// The synthetic demo account of make cloud-demo, owner of Demo Comercial SAS.
const DEMO = { email: 'demo@example.com', password: 'password', company: 'Demo Comercial SAS' }
const ORIGIN = 'http://localhost:8601'

interface Route {
  readonly path: string
  readonly guest: boolean
  readonly public: boolean
}

interface Listed {
  readonly id: string
  readonly [field: string]: unknown
}

/** The value of each parameter of a route: an object of the demo data, or a link of an email that is not valid. */
const PARAMETERS: Readonly<Record<string, (first: (resource: string, accept?: (item: Listed) => boolean) => Promise<string>) => Promise<string>>> = {
  '/invoices/:id': (first) => first('invoices', (invoice) => (invoice.electronic as { status?: string } | null)?.status === 'accepted' && (invoice.delivery as { status?: string } | null)?.status === 'delivered'),
  '/invoices/credit-notes/:id': (first) => first('credit-notes'),
  '/invoices/debit-notes/:id': (first) => first('debit-notes'),
  '/payments/:id': (first) => first('payments', (payment) => payment.status !== 'voided'),
  '/contacts/:id': (first) => first('contacts'),
  '/reset-password/:token': async () => 'a-link-of-the-tour-that-is-not-a-token',
  '/invitations/:token': async () => 'a-link-of-the-tour-that-is-not-a-token',
}

/** The buttons of each route that open its main dialogs; each opens, and closes with Escape without sending anything. */
const DIALOGS: Readonly<Record<string, readonly string[]>> = {
  '/items': ['New item'],
  '/settings/taxes': ['New tax'],
  '/settings/withholdings': ['New withholding'],
  '/settings/numbering': ['New numbering'],
  '/settings/dian': ['Upload certificate'],
  '/settings/team': ['Invite member'],
  '/settings/developers': ['Create key', 'Add subscription'],
  '/invoices/:id': ['View PDF', 'View XML', 'View response', 'Send again'],
  '/payments/:id': ['Void payment'],
}

/** The routes of the web app as its router declares them, read from the running app (Vue keeps it on its root element). */
async function routesOf(page: Page): Promise<Route[]> {
  const routes = await page.evaluate(() => {
    interface RouteRecord {
      readonly path: string
      readonly redirect?: unknown
      readonly components?: unknown
      readonly meta: { readonly guest?: boolean; readonly public?: boolean }
    }
    const root = document.querySelector('#app') as (Element & { __vue_app__?: { config: { globalProperties: { $router?: { getRoutes(): RouteRecord[] } } } } }) | null
    const router = root?.__vue_app__?.config.globalProperties.$router

    if (router === undefined) {
      throw new Error('The web app exposes no router.')
    }

    return router
      .getRoutes()
      .filter((route) => route.redirect === undefined && route.components !== undefined && !route.path.includes('pathMatch'))
      .map((route) => ({ path: route.path, guest: route.meta.guest === true, public: route.meta.public === true }))
  })

  return [...new Map(routes.map((route) => [route.path, route])).values()]
}

/** Loads a path as a deep link through the edge and waits for the web app to draw it, without a redirect. */
async function visit(page: Page, path: string): Promise<void> {
  const answer = await page.goto(path)
  expect(answer?.status(), path).toBe(200)
  await page.waitForLoadState('networkidle')
  await page.waitForFunction(() => (document.querySelector('#app')?.children.length ?? 0) > 0)
  expect(new URL(page.url()).pathname, `${path} was drawn, not redirected`).toBe(path)
}

/** Opens a dialog with its button, sees it, and closes it with Escape. False when the button is disabled. */
async function openDialog(page: Page, label: string): Promise<boolean> {
  const button = page.getByRole('button', { name: label, exact: true }).first()
  await expect(button, label).toBeVisible()

  if (!(await button.isEnabled())) {
    return false
  }

  await button.click()
  const dialog = page.getByRole('dialog').or(page.getByRole('alertdialog')).first()
  await expect(dialog, label).toBeVisible()
  await page.waitForLoadState('networkidle')
  await page.keyboard.press('Escape')
  await expect(dialog, label).toBeHidden()

  return true
}

/** Opens the first lists of a page (selects and search selects), each with its own style element, and closes them. */
async function openLists(page: Page): Promise<number> {
  const lists = page.getByRole('combobox')
  const count = Math.min(await lists.count(), 3)
  let opened = 0

  for (let index = 0; index < count; index++) {
    const list = lists.nth(index)

    if (!(await list.isVisible()) || !(await list.isEnabled())) {
      continue
    }

    await list.click()
    await page.getByRole('listbox').or(page.getByRole('option')).first().waitFor({ state: 'visible', timeout: 2_000 }).then(
      () => (opened += 1),
      () => undefined,
    )
    await page.keyboard.press('Escape')
  }

  return opened
}

/** Opens the account menu and picks one of its items. */
async function fromAccountMenu(page: Page, item: { role: 'menuitem' | 'menuitemradio'; name: string }): Promise<void> {
  await page.getByRole('button', { name: 'Account menu' }).click()
  await page.getByRole(item.role, { name: item.name }).click()
}

test('a tour of every route of the web app, its main dialogs, menus and lists, with nothing refused', async ({ page }) => {
  test.setTimeout(600_000)
  const visited: string[] = []
  const dialogs: string[] = []
  let lists = 0

  // The routes of the router, and the pages of visitors without a session first.
  await visit(page, '/login')
  const routes = await routesOf(page)
  expect(routes.length).toBeGreaterThan(25)
  const withoutValue = routes.filter((route) => route.path.includes(':') && PARAMETERS[route.path] === undefined).map((route) => route.path)
  expect(withoutValue, 'every route with a parameter has a value in the tour').toEqual([])

  for (const route of routes.filter((candidate) => candidate.guest || candidate.public)) {
    const path = route.path.includes(':') ? route.path.replace(/:[a-z]+/i, await (PARAMETERS[route.path] as () => Promise<string>)()) : route.path
    await visit(page, path)
    visited.push(route.path)
  }

  // Signed in, in Demo Comercial SAS.
  await visit(page, '/login')
  await page.getByLabel('Email', { exact: true }).fill(DEMO.email)
  await page.getByLabel('Password', { exact: true }).fill(DEMO.password)
  await page.getByRole('button', { name: 'Sign in' }).click()
  const switcher = page.getByRole('button', { name: 'Switch company' })
  await switcher.click()
  await page.getByRole('menuitem', { name: new RegExp(DEMO.company) }).click()
  await expect(switcher).toContainText(DEMO.company)

  const companyId = await page.evaluate(() => localStorage.getItem('dian-einvoice.current-company'))
  expect(companyId).toBeTruthy()
  const first = async (resource: string, accept: (item: Listed) => boolean = () => true): Promise<string> => {
    const answer = await page.request.get(`/api/v1/${resource}?per_page=50`, { headers: { Accept: 'application/json', Referer: `${ORIGIN}/`, 'X-Company-Id': companyId ?? '' } })
    expect(answer.ok(), resource).toBe(true)
    const found = ((await answer.json()) as { data: Listed[] }).data.find(accept)
    expect(found, `the demo data has ${resource} for the tour`).toBeDefined()

    return (found as Listed).id
  }

  for (const route of routes.filter((candidate) => !candidate.guest && !candidate.public)) {
    const parameter = PARAMETERS[route.path]
    const path = parameter === undefined ? route.path : route.path.replace(/:[a-z]+/i, await parameter(first))
    await visit(page, path)
    visited.push(route.path)

    for (const label of DIALOGS[route.path] ?? []) {
      if (await openDialog(page, label)) {
        dialogs.push(`${route.path} ${label}`)
      }
    }

    lists += await openLists(page)
  }

  // On the dashboard: the hover card of a chart, the command palette, About, a new company and every theme.
  await visit(page, '/')
  const chart = page.locator('[data-chart]').first()
  await expect(chart).toBeVisible()
  const box = await chart.boundingBox()

  for (const share of [0.2, 0.5, 0.8]) {
    await page.mouse.move((box?.x ?? 0) + (box?.width ?? 0) * share, (box?.y ?? 0) + (box?.height ?? 0) / 2)
    await page.waitForTimeout(300)
  }

  // The hover card is markup Unovis inserts as HTML (src/components/dashboard/ChartTooltip.vue).
  await expect(page.locator('.min-w-40.bg-popover').first(), 'the hover card of the chart').toBeVisible()
  dialogs.push('/ hover card of a chart')

  await page.getByRole('button', { name: 'Open the command palette' }).click()
  const palette = page.getByRole('dialog')
  await expect(palette).toBeVisible()
  await page.keyboard.type('Cordillera')
  await page.waitForLoadState('networkidle')
  await page.keyboard.press('Escape')
  await expect(palette).toBeHidden()
  dialogs.push('/ command palette')

  await fromAccountMenu(page, { role: 'menuitem', name: 'About' })
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.keyboard.press('Escape')
  dialogs.push('/ About')

  await switcher.click()
  await page.getByRole('menuitem', { name: 'New company' }).click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.keyboard.press('Escape')
  dialogs.push('/ New company')

  for (const theme of ['Dark', 'Light', 'System']) {
    await fromAccountMenu(page, { role: 'menuitemradio', name: theme })
    await page.waitForTimeout(300)
  }

  // What the tour went through, for the report of make cloud-e2e.
  console.log(`The tour visited ${visited.length} routes of ${routes.length}, opened ${dialogs.length} dialogs and ${lists} lists: ${dialogs.join('; ')}.`)
  expect(visited.length).toBe(routes.length)
  expect(dialogs.length).toBeGreaterThanOrEqual(12)
})
