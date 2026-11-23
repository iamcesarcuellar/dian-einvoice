import { defineConfig } from '@playwright/test'

// make screenshots (scripts/screenshots.sh): the screenshots and the frames of the GIFs of the README, in English or in
// Spanish (SCREENSHOTS_LANGUAGE), against the local stack and its synthetic demo data, in the full Chromium, whose viewer
// of PDFs draws in a page as in a desktop browser. The frames go to SCREENSHOTS_RAW inside the container;
// screenshots/encode.mjs then writes docs/assets, or docs/assets/es.
export default defineConfig({
  testDir: '.',
  testMatch: 'screens.spec.ts',
  outputDir: '/tmp/screenshots/test-results',
  // The outage of the DIAN waits for every transmission attempt to fail (2, 5, 15, 45 and 120 s between them).
  timeout: 900_000,
  expect: { timeout: 30_000 },
  workers: 1,
  retries: 0,
  forbidOnly: true,
  reporter: 'list',
  use: {
    channel: 'chromium',
    // The browser in British English, or in Colombian Spanish for the Spanish screenshots (SCREENSHOTS_LANGUAGE=es): on
    // Linux, Chromium takes the language of its own interface, which the native date field follows (day, month, year),
    // from the environment, not from the locale of a context.
    launchOptions: {
      env:
        process.env.SCREENSHOTS_LANGUAGE === 'es'
          ? { ...process.env, LANG: 'es_CO.UTF-8', LANGUAGE: 'es_CO:es' }
          : { ...process.env, LANG: 'en_GB.UTF-8', LANGUAGE: 'en_GB:en' },
    },
    // Nothing waits for ever: an action or a page that does not come fails the run.
    actionTimeout: 30_000,
    navigationTimeout: 60_000,
    trace: 'off',
    screenshot: 'off',
    video: 'off',
  },
  webServer: {
    command: 'node ../forward.mjs',
    url: 'http://localhost:8601/api/v1/health',
    reuseExistingServer: false,
    timeout: 30_000,
  },
})
