import { fileURLToPath, URL } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

// The browser only talks to this dev server. API, CSRF cookie and API docs requests are
// forwarded to the backend, so the session cookie lives on a single origin.
const apiTarget = process.env.API_URL ?? 'http://localhost:8600'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': apiTarget,
      '/sanctum': apiTarget,
      '/docs': apiTarget,
    },
  },
})
