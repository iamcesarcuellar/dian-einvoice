import { createI18n } from 'vue-i18n'
import en from './en'
import es from './es'

/** The languages of the interface: English, the default, and Colombian Spanish. Every UI string goes through a key. */
export const LANGUAGES = ['en', 'es'] as const

export type Language = (typeof LANGUAGES)[number]

/** Where this browser keeps the language the user chose. */
const STORAGE_KEY = 'dian-einvoice.language'

function isLanguage(value: unknown): value is Language {
  return value === 'en' || value === 'es'
}

/** The language chosen in this browser; the first time, the browser's own: Spanish when it starts with "es", English otherwise. */
function initialLanguage(): Language {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)

    if (isLanguage(stored)) {
      return stored
    }
  } catch {
    // No storage, as in a private window: the browser's language decides.
  }

  const browser = typeof navigator === 'undefined' ? '' : navigator.language

  return browser.toLowerCase().startsWith('es') ? 'es' : 'en'
}

/** The lang attribute of the page, which screen readers and the browser's spell checker follow. */
function markDocument(language: Language): void {
  if (typeof document !== 'undefined') {
    document.documentElement.lang = language === 'es' ? 'es-CO' : 'en'
  }
}

export const i18n = createI18n({
  legacy: false,
  locale: initialLanguage(),
  fallbackLocale: 'en',
  messages: { en, es },
})

markDocument(currentLanguage())

/** The language of the interface right now; the API client sends it in Accept-Language. */
export function currentLanguage(): Language {
  const language = i18n.global.locale.value

  return isLanguage(language) ? language : 'en'
}

/** Switches the interface to the language and keeps the choice in this browser. */
export function setLanguage(language: Language): void {
  i18n.global.locale.value = language
  markDocument(language)

  try {
    localStorage.setItem(STORAGE_KEY, language)
  } catch {
    // The choice lasts until the page is reloaded.
  }
}
