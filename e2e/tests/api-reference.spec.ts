import { expect, test } from './policy'

// The API reference behind the edge (make cloud-e2e only), in English and in Spanish: on the origin of the web app,
// under a policy of its own whose third-party renderer is pinned by Subresource Integrity and whose inline scripts
// are allowed by hash. The local stack serves it on the origin of the API, which the browser of make e2e does not open.
test.skip(process.env.EDGE !== '1', 'The API reference shares the origin of the web app only behind the edge.')

const references = [
  { path: '/docs/api', lang: 'en', section: 'Idempotency', group: 'Invoices', other: { name: 'Español', path: '/docs/api/es' } },
  { path: '/docs/api/es', lang: 'es', section: 'Idempotencia', group: 'Facturas', other: { name: 'English', path: '/docs/api' } },
]

for (const reference of references) {
  test(`the API reference at ${reference.path} renders under its own policy, with nothing refused`, async ({ page }) => {
    const answer = await page.goto(reference.path)
    expect(answer?.status()).toBe(200)

    const policy = answer?.headers()['content-security-policy'] ?? ''
    expect(policy).toContain("'sha256-")
    expect(policy).not.toMatch(/script-src[^;]*'unsafe-(inline|eval)'/)
    await expect(page.locator('html')).toHaveAttribute('lang', reference.lang)

    // Stoplight Elements draws the description and the groups of the document, in the language of the page, and the
    // first line of the description links to the reference in the other language.
    await expect(page.getByText(reference.section, { exact: true }).first()).toBeVisible()
    await expect(page.getByText(reference.group, { exact: true }).first()).toBeVisible()
    const other = page.getByRole('link', { name: reference.other.name, exact: true }).first()
    await expect(other).toHaveAttribute('href', new RegExp(`^https?://[^/]+${reference.other.path}$`))
  })
}
