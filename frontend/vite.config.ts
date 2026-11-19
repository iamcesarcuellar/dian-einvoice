import { fileURLToPath, URL } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'
import product from './src/config/product.json' with { type: 'json' }

// The browser only talks to this dev server. API and CSRF cookie requests are forwarded to the
// backend, so the session cookie lives on a single origin. The API reference is not forwarded: it
// loads Stoplight Elements from a CDN, so it stays on the origin of the API (product.apiUrl), which
// no browser session uses.
const apiTarget = process.env.API_URL ?? 'http://localhost:8600'

export default defineConfig({
  plugins: [
    vue(),
    tailwindcss(),
    // The browser title of index.html comes from the product config, like every other place.
    {
      name: 'product-name',
      transformIndexHtml: (html: string) => html.replaceAll('%PRODUCT_NAME%', product.name),
    },
  ],
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
    },
  },
})
