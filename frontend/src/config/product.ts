import config from './product.json'

/**
 * The one place for the visible name of the product: the browser title, the sidebar, the sign-in
 * page and the About dialog read it from here (index.html too, through vite.config.ts).
 */
export const product = {
  name: config.name,
  /**
   * The origin of the API for integrations: API keys call it, and its reference lives there. The web
   * app itself calls /api on its own origin (the dev server forwards it); the reference is not
   * forwarded, so its third-party scripts never run on the origin of the session.
   */
  apiUrl: config.apiUrl,
  apiReferenceUrl: `${config.apiUrl}/docs/api`,
  /**
   * The synthetic demo account of make demo, and the local mailbox where every email lands. The
   * sign-in pages offer them only on the local development server (import.meta.env.DEV), never in
   * a production build.
   */
  localDemo: import.meta.env.DEV ? config.localDemo : null,
} as const
