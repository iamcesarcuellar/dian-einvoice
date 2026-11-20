import { expect, test } from './policy'

// The API reference behind the edge (make cloud-e2e only): on the origin of the web app, under a policy of its own
// whose third-party renderer is pinned by Subresource Integrity and whose inline scripts are allowed by hash. The
// local stack serves it on the origin of the API, which the browser of make e2e does not open.
test.skip(process.env.EDGE !== '1', 'The API reference shares the origin of the web app only behind the edge.')

test('the API reference renders under its own policy, with nothing refused', async ({ page }) => {
  const answer = await page.goto('/docs/api')
  expect(answer?.status()).toBe(200)

  const policy = answer?.headers()['content-security-policy'] ?? ''
  expect(policy).toContain("'sha256-")
  expect(policy).not.toMatch(/script-src[^;]*'unsafe-(inline|eval)'/)

  // Stoplight Elements draws the description and the groups of the document.
  await expect(page.getByText('Idempotency', { exact: true }).first()).toBeVisible()
  await expect(page.getByText('Invoices', { exact: true }).first()).toBeVisible()
})
