import en from '/messages/en.ts'
import es from '/messages/es.ts'

/**
 * The web app's messages (frontend/src/i18n, mounted read-only at /messages), so a test names what it clicks and reads in
 * the language of the interface it runs in, as the app writes it: t('es', 'invoices.editor.issue') is "Emitir factura".
 */
export type Language = 'en' | 'es'

interface Tree {
  [key: string]: string | Tree
}

/**
 * The messages object of a file outside the tests: /messages has no package.json, so the test runner loads it as
 * CommonJS, and its default export may arrive wrapped in the module object.
 */
function unwrap(module: unknown): Tree {
  const inner = (module as { default?: unknown }).default

  return (typeof inner === 'object' && inner !== null ? inner : module) as Tree
}

const MESSAGES: Record<Language, Tree> = { en: unwrap(en), es: unwrap(es) }

/** The language of a browser locale, as the web app picks it the first time: Spanish when it starts with "es". */
export function languageOf(locale: string): Language {
  return locale.toLowerCase().startsWith('es') ? 'es' : 'en'
}

/**
 * The message of a dotted key with its placeholders filled in; for a plural message (forms separated by "|"), the form
 * vue-i18n takes for the count: with two forms one | many, with three zero | one | many.
 */
export function t(language: Language, key: string, values: Record<string, string | number> = {}, count?: number): string {
  let node: string | Tree | undefined = MESSAGES[language]

  for (const part of key.split('.')) {
    node = typeof node === 'object' ? node[part] : undefined
  }

  if (typeof node !== 'string') {
    throw new Error(`The ${language} messages have no ${key}.`)
  }

  const forms = node.split(/\s*\|\s*/)
  const index = count === undefined || forms.length === 1 ? 0 : forms.length === 2 ? (count === 1 ? 0 : 1) : Math.min(count, 2)
  const form = forms[index] ?? node

  return form.replace(/\{\s*(\w+)\s*\}/g, (match, name: string) => (name in values ? String(values[name]) : match))
}
