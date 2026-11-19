import { defineConfig, mergeConfig } from 'vitest/config'
import viteConfig from './vite.config.ts'

// The unit tests run with the Vite config of the app (Vue plugin, @ alias) in a simulated browser.
export default mergeConfig(
  viteConfig,
  defineConfig({
    test: {
      environment: 'happy-dom',
      include: ['src/**/*.test.ts'],
      clearMocks: true,
    },
  }),
)
