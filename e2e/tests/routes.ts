import type { Page } from '@playwright/test'

/** A route of the web app's router: its path, and whether it is for visitors without a session or for everyone. */
export interface Route {
  readonly path: string
  readonly guest: boolean
  readonly public: boolean
}

/** The routes of the web app as its router declares them, read from the running app (Vue keeps it on its root element). */
export async function routesOf(page: Page): Promise<Route[]> {
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
