import { createI18n } from 'vue-i18n'
import en from './en'

// English only for now; Spanish arrives in the Polish phase. Every UI string goes through a key.
export const i18n = createI18n({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  messages: { en },
})
