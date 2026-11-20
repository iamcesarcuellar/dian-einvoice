import { expect, test as base } from '@playwright/test'

/**
 * Every test runs with a watch on the Content-Security-Policy: each violation the browser reports, in any frame of
 * the page, fails the test at its end. Against the emulated cloud (make cloud-e2e) the web app is the production
 * build behind the policy of the edge; against the development server of the local stack (make e2e) there is no
 * policy, so there is nothing to report.
 */
export const test = base.extend<{ violations: string[] }>({
  violations: [
    async ({ page }, use) => {
      const violations: string[] = []

      // The report of the browser itself, and the event of each document, in every frame.
      page.on('console', (message) => {
        if (/Content[ -]Security[ -]Policy/i.test(message.text())) {
          violations.push(`${new URL(page.url()).pathname}: ${message.text()}`)
        }
      })
      await page.addInitScript(() => {
        document.addEventListener('securitypolicyviolation', (event) => {
          console.error(`Content Security Policy violation: ${event.effectiveDirective} refused ${event.blockedURI || 'inline'} (${event.sourceFile}:${event.lineNumber})`)
        })
      })

      await use(violations)

      expect(violations, 'violations of the Content-Security-Policy').toEqual([])
    },
    { auto: true },
  ],
})

export { expect }
