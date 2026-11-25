import type { Language } from '@/i18n'
import config from './product.json'

function apiOrigin(): string {
  return import.meta.env.DEV ? config.apiUrl : window.location.origin
}

/**
 * The one place for the visible name of the product: the browser title, the sidebar, the sign-in
 * page and the About dialog read it from here (index.html too, through vite.config.ts).
 */
export const product = {
  name: config.name,
  /**
   * The origin of the API for integrations: API keys call it, and its reference lives there. The web
   * app itself calls /api on its own origin. On the development server of the local stack, which
   * forwards only /api and /sanctum, the API keeps an origin of its own (config.apiUrl); a production
   * build is served by the edge, which serves the API and its reference on the origin of the web app.
   */
  apiUrl: apiOrigin(),
  /** The API reference in a language of the interface: /docs/api in English, /docs/api/es in Spanish. */
  apiReferenceUrl: (language: Language): string => `${apiOrigin()}/docs/api${language === 'es' ? '/es' : ''}`,
  /**
   * The synthetic demo account of make demo, and the local mailbox where every email lands. The
   * sign-in pages offer them only on the local development server (import.meta.env.DEV), never in
   * a production build.
   */
  localDemo: import.meta.env.DEV ? config.localDemo : null,
} as const
