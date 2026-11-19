import config from './product.json'

/**
 * The one place for the visible name of the product: the browser title, the sidebar, the sign-in
 * page and the About dialog read it from here (index.html too, through vite.config.ts).
 */
export const product = {
  name: config.name,
  /**
   * The synthetic demo account of make demo. The sign-in page offers it only on the local
   * development server (import.meta.env.DEV), never in a production build.
   */
  localDemo: import.meta.env.DEV ? config.localDemo : null,
} as const
