import { defineConfig, devices } from '@playwright/test'

// The end-to-end test of the web app against the whole local stack (API, queue workers, outbox relay,
// DIAN simulator and mailbox) with the synthetic demo data of make demo, or, with EDGE=1, against the emulated
// cloud behind the edge (make cloud-e2e, after make cloud-demo), in the full Chromium, whose viewer of PDFs runs
// under the policy of the web app as in a desktop browser.
export default defineConfig({
  testDir: './tests',
  outputDir: './test-results',
  // The real workers run the electronic chain (XML, signature, DIAN simulator) and the delivery.
  timeout: 300_000,
  expect: { timeout: 30_000 },
  workers: 1,
  retries: 0,
  forbidOnly: true,
  reporter: 'list',
  use: {
    ...devices['Desktop Chrome'],
    ...(process.env.EDGE === '1' ? { channel: 'chromium' } : {}),
    baseURL: 'http://localhost:8601',
    locale: 'en-US',
    timezoneId: 'America/Bogota',
    // A trace only for a failed run, under test-results; no screenshots or videos.
    trace: 'retain-on-failure',
    screenshot: 'off',
    video: 'off',
  },
  webServer: {
    command: 'node forward.mjs',
    url: 'http://localhost:8601/api/v1/health',
    reuseExistingServer: false,
    timeout: 30_000,
  },
})
