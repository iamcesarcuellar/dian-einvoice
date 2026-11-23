import { describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import en from './en'
import es from './es'

// The two languages of the web app say the same things: every key of en.ts is in es.ts and nothing more, each message
// has the same placeholders, plural messages have the same forms, and vue-i18n reads every message without a warning.

interface Tree {
  [key: string]: string | Tree
}

/** Every message by its dotted key. */
function messages(tree: Tree, prefix = ''): Map<string, string> {
  const found = new Map<string, string>()

  for (const [key, value] of Object.entries(tree)) {
    const path = prefix === '' ? key : `${prefix}.${key}`

    if (typeof value === 'string') {
      found.set(path, value)
    } else {
      for (const [inner, message] of messages(value, path)) {
        found.set(inner, message)
      }
    }
  }

  return found
}

/** The forms of a plural message, "No invoices | {n} invoice | {n} invoices"; one form for any other message. */
function forms(message: string): string[] {
  return message.split(/\s*\|\s*/)
}

/** The placeholders of a message or of one of its forms, such as ["n"] for "{n} invoices". */
function placeholders(text: string): string[] {
  return [...new Set([...text.matchAll(/\{\s*([A-Za-z_][\w]*)\s*\}/g)].map((match) => match[1] ?? ''))].sort()
}

const english = messages(en)
const spanish = messages(es)

/**
 * The pattern of a calendar date: each language writes it its own way from the same parts, which lib/format.ts fills
 * in, so its placeholders need only be some of them, the year included. English writes "19 Nov 2026", Spanish
 * "19/11/2026".
 */
const DATE_PATTERNS = new Set(['format.date'])
const DATE_PARTS = new Set(['day', 'dd', 'mm', 'month', 'year'])

describe('the Spanish messages', () => {
  it('have every key of the English ones, and no other', () => {
    expect(
      [...spanish.keys()].filter((key) => !english.has(key)),
      'keys only in es.ts',
    ).toEqual([])
    expect(
      [...english.keys()].filter((key) => !spanish.has(key)),
      'keys missing from es.ts',
    ).toEqual([])
  })

  it('have the same plural forms as the English ones, each with the same placeholders', () => {
    const different = [...english].flatMap(([key, message]) => {
      const translated = spanish.get(key) ?? ''
      const theirs = forms(message)
      const ours = forms(translated)

      if (ours.length !== theirs.length || ours.some((form) => form.trim() === '')) {
        return [`${key}: ${theirs.length} forms in English, ${ours.length} in Spanish`]
      }

      if (DATE_PATTERNS.has(key)) {
        const parts = placeholders(translated)

        return parts.includes('year') && parts.every((part) => DATE_PARTS.has(part))
          ? []
          : [`${key}: a date pattern takes only {day}, {dd}, {month}, {mm} and {year}, the year included`]
      }

      return theirs.flatMap((form, index) =>
        placeholders(form).join(',') === placeholders(ours[index] ?? '').join(',')
          ? []
          : [`${key}, form ${index + 1}: {${placeholders(form).join('}, {')}} in English, {${placeholders(ours[index] ?? '').join('}, {')}} in Spanish`],
      )
    })

    expect(different).toEqual([])
  })

  it('are all read by vue-i18n, with their placeholders filled in and without a warning', () => {
    const warn = vi.spyOn(console, 'warn')
    const error = vi.spyOn(console, 'error')
    const i18n = createI18n({ legacy: false, locale: 'es', fallbackLocale: 'en', messages: { en, es }, missingWarn: true, fallbackWarn: true })

    const unread = [...spanish].flatMap(([key, message]) => {
      const values = Object.fromEntries(placeholders(message).map((name) => [name, `<${name}>`]))
      const written = forms(message).length > 1 ? i18n.global.t(key, values, 2) : i18n.global.t(key, values)

      return written === key || /[{}]/.test(written) ? [`${key}: ${written}`] : []
    })

    expect(unread).toEqual([])
    expect(warn).not.toHaveBeenCalled()
    expect(error).not.toHaveBeenCalled()
  })

  it('write dates in Spanish as dd/mm/aaaa', () => {
    expect(es.format.date).toBe('{dd}/{mm}/{year}')
  })
})
