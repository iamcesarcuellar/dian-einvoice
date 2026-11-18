import '@fontsource-variable/inter'
import './main.css'

import { VueQueryPlugin } from '@tanstack/vue-query'
import { createPinia } from 'pinia'
import { createApp } from 'vue'

import App from './App.vue'
import { i18n } from './i18n'
import { queryClient } from './lib/query'
import { router } from './router'

const app = createApp(App)

app.use(createPinia())
app.use(i18n)
app.use(VueQueryPlugin, { queryClient })
app.use(router)

app.mount('#app')
