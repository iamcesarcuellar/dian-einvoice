// The Spanish translation of the documentation (make docs-check, through check.mjs; make docs-stamp, through
// stamp.mjs). Every document in English has its Spanish pair and the other way round: docs/<name>.md and
// docs/es/<name>.md, docs/runbooks/<name>.md and docs/es/runbooks/<name>.md, and at the root README, SECURITY,
// CONTRIBUTING, CHANGELOG (.md and .es.md) and NOTICE with NOTICE.es.txt; LICENSE keeps its legal text in English
// only. Each translation carries the SHA-256 of the original it translates, so an original that changes fails the
// check until its translation follows and make docs-stamp seals it again; the two generated documents (cost and
// third-party licenses) carry none, since their generator writes both and its own --check covers them.
//
// A translation keeps everything of its original that is not prose, section by section: the code blocks (except for
// the comments of shell blocks and the labels of Mermaid diagrams, which are translated), the inline code, the links
// (to the same destination, or to the Spanish pair of a document that has one), the figures (the notation changes,
// not the value) and the structure (the headings by level and in order, the rows of each table, the items of each
// list). In Spanish, a paragraph left in English fails, and so does a term the glossary of docs/es/README.md replaces
// (its column "En vez de"). Both language lines are checked: right under the title, each file names its pair. Every
// legitimate difference is declared in translations.json with its reason, and an exception that matches nothing
// fails. No dependencies: make test-tools tests it (translations.test.mjs).
import { createHash } from 'node:crypto'
import { existsSync, readFileSync } from 'node:fs'
import { join, posix } from 'node:path'

/** The documents at the root with their Spanish pair. */
export const ROOT_PAIRS = new Map([
  ['README.md', 'README.es.md'],
  ['SECURITY.md', 'SECURITY.es.md'],
  ['CONTRIBUTING.md', 'CONTRIBUTING.es.md'],
  ['CHANGELOG.md', 'CHANGELOG.es.md'],
  ['NOTICE', 'NOTICE.es.txt'],
])

/** The documents a generator writes in both languages from the same data: its own --check covers them. */
export const GENERATED = new Set(['docs/cost.md', 'docs/third-party-licenses.md'])

/** NOTICE and its translation are plain text: each names the other in a line of its own. */
export const NOTICE_LINES = {
  original: 'Spanish translation, for reference only: NOTICE.es.txt. This file, in English, is the one that counts.',
  translation: 'Traducción al español de NOTICE, solo como referencia: el que vale es NOTICE, en inglés.',
}

const ROOT_TRANSLATIONS = new Map([...ROOT_PAIRS].map(([original, translation]) => [translation, original]))

/** The Spanish pair of a document in English, or null when it has none. */
export function translationOf(path) {
  if (ROOT_PAIRS.has(path)) {
    return ROOT_PAIRS.get(path)
  }

  const match = /^docs\/(runbooks\/)?([^/]+\.md)$/.exec(path)

  return match === null ? null : `docs/es/${match[1] ?? ''}${match[2]}`
}

/** The original in English of a Spanish document, or null when it is not a translation. */
export function originalOf(path) {
  if (ROOT_TRANSLATIONS.has(path)) {
    return ROOT_TRANSLATIONS.get(path)
  }

  const match = /^docs\/es\/(runbooks\/)?([^/]+\.md)$/.exec(path)

  return match === null ? null : `docs/${match[1] ?? ''}${match[2]}`
}

/** Whether a path of the repository is a document that needs a pair: an original or a translation. */
export function isDocument(path) {
  return ROOT_PAIRS.has(path) || ROOT_TRANSLATIONS.has(path) || /^docs\/(es\/)?(runbooks\/)?[^/]+\.md$/.test(path) || /^docs\/es\//.test(path)
}

/**
 * The pairs of the repository, from the list of its files: each with its original and its translation, and the
 * problems of the documents left without a pair.
 */
export function pairsOf(files) {
  const present = new Set(files)
  const pairs = []
  const problems = []

  for (const path of [...present].filter(isDocument).sort()) {
    const original = originalOf(path)

    if (path.startsWith('docs/es/') || ROOT_TRANSLATIONS.has(path)) {
      if (original === null) {
        problems.push(`${path}: not the translation of any document (docs/es holds docs/<name>.md and docs/runbooks/<name>.md only)`)
      } else if (!present.has(original)) {
        problems.push(`${path}: its original ${original} does not exist`)
      }

      continue
    }

    const translation = translationOf(path)

    if (translation === null) {
      continue
    }

    if (present.has(translation)) {
      pairs.push({ original: path, translation, generated: GENERATED.has(path) })
    } else {
      problems.push(`${path}: no Spanish translation (${translation})`)
    }
  }

  return { pairs, problems }
}

export const sha256 = (text) => createHash('sha256').update(text).digest('hex')

const STAMP = /SHA-256 ([0-9a-f]{64})/

/** The stamp line of a translation: the SHA-256 of the original it translates. */
export function stampLine(original, hash) {
  return original === 'NOTICE'
    ? `Traducción de NOTICE con SHA-256 ${hash}. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original.`
    : `<!-- Traducción de ${original} con SHA-256 ${hash}. make docs-stamp actualiza esta línea cuando la traducción ya sigue al original. -->`
}

/** Whether a line is the stamp line of a translation. */
const isStamp = (line) => line.includes('make docs-stamp') && STAMP.test(line)

/** The SHA-256 a translation says it translates, or null. */
export function stampOf(text) {
  const line = text.split('\n').find((candidate) => candidate.includes('make docs-stamp') && STAMP.test(candidate))

  return line === undefined ? null : STAMP.exec(line)[1]
}

/**
 * The translation with its stamp set to a hash: the line is replaced where it is, or written under the language line
 * (a Markdown document) or at the end (NOTICE.es.txt).
 */
export function withStamp(text, original, hash) {
  const line = stampLine(original, hash)
  const lines = text.split('\n')
  const at = lines.findIndex((candidate) => candidate.includes('make docs-stamp') && STAMP.test(candidate))

  if (at !== -1) {
    lines[at] = line

    return lines.join('\n')
  }

  if (original === 'NOTICE') {
    const body = text.replace(/\n+$/, '')

    return `${body}\n\n${line}\n`
  }

  const language = lines.findIndex((candidate) => LANGUAGE_LINE.test(candidate))
  const after = language === -1 ? lines.findIndex((candidate) => /^#\s/.test(candidate)) : language

  lines.splice(after + 1, 0, '', line)

  return lines.join('\n')
}

// ---------------------------------------------------------------------------------------------------------------
// Markdown, as far as these documents use it: ATX headings, fenced code, tables, lists, quotes, HTML comments and
// paragraphs; inline, code spans, links, images, reference links, autolinks and bare URLs.
// ---------------------------------------------------------------------------------------------------------------

const ITEM = /^(\s*)([-*+]|\d+[.)])\s+(.*)$/
const FENCE = /^\s*(```|~~~)\s*([\w-]*)/
const HEADING = /^(#{1,6})\s+(.*?)\s*#*\s*$/

/** The blocks of a Markdown document, in order, each with the number of its first line. */
export function parse(text) {
  const lines = text.replace(/\r/g, '').split('\n')
  const blocks = []
  let index = 0

  const starts = (line) => line.trim() === '' || FENCE.test(line) || HEADING.test(line) || /^\s*\|/.test(line) || ITEM.test(line) || /^\s*>/.test(line) || line.trim().startsWith('<!--')

  while (index < lines.length) {
    const line = lines[index]
    const number = index + 1
    const fence = FENCE.exec(line)

    if (line.trim() === '') {
      index += 1
    } else if (fence !== null) {
      const marker = fence[1]
      const body = []
      index += 1

      while (index < lines.length && !new RegExp(`^\\s*${marker}\\s*$`).test(lines[index])) {
        body.push(lines[index])
        index += 1
      }

      index += 1
      blocks.push({ type: 'code', language: fence[2], code: body.join('\n'), line: number })
    } else if (line.trim().startsWith('<!--')) {
      const body = [line]

      while (!body.at(-1).includes('-->') && index + 1 < lines.length) {
        index += 1
        body.push(lines[index])
      }

      index += 1
      blocks.push({ type: 'comment', text: body.join('\n'), line: number })
    } else if (HEADING.test(line)) {
      const [, hashes, heading] = HEADING.exec(line)
      blocks.push({ type: 'heading', level: hashes.length, text: heading, line: number })
      index += 1
    } else if (/^\s*\|/.test(line)) {
      const rows = []

      while (index < lines.length && /^\s*\|/.test(lines[index])) {
        if (!/^\s*\|(\s*:?-{3,}:?\s*\|)+\s*$/.test(lines[index])) {
          rows.push(cells(lines[index]))
        }

        index += 1
      }

      blocks.push({ type: 'table', rows, line: number })
    } else if (ITEM.test(line)) {
      const [, indent, , first] = ITEM.exec(line)
      const body = [first]
      index += 1

      while (index < lines.length && lines[index].trim() !== '' && !starts(lines[index]) && /^\s+/.test(lines[index])) {
        body.push(lines[index].trim())
        index += 1
      }

      blocks.push({ type: 'item', indent: indent.length, text: body.join(' '), line: number })
    } else if (/^\s*>/.test(line)) {
      const body = []

      while (index < lines.length && /^\s*>/.test(lines[index])) {
        body.push(lines[index].replace(/^\s*>\s?/, ''))
        index += 1
      }

      blocks.push({ type: 'quote', text: body.join(' '), line: number })
    } else {
      const body = [line.trim()]
      index += 1

      while (index < lines.length && !starts(lines[index])) {
        body.push(lines[index].trim())
        index += 1
      }

      blocks.push({ type: 'paragraph', text: body.join(' '), line: number })
    }
  }

  return blocks
}

/** The cells of a table row, the escaped pipes kept inside their cell. */
function cells(row) {
  return row
    .trim()
    .replace(/^\|/, '')
    .replace(/\|$/, '')
    .split(/(?<!\\)\|/)
    .map((cell) => cell.trim())
}

/** The text of a block that is prose: its cells for a table row, its text otherwise. */
const textsOf = (block) => (block.type === 'table' ? block.rows.flat() : block.type === 'heading' || block.type === 'paragraph' || block.type === 'item' || block.type === 'quote' ? [block.text] : [])

/** The reference definitions of a document: [label]: target. */
function definitionsOf(blocks) {
  const definitions = new Map()

  for (const block of blocks.filter((candidate) => candidate.type === 'paragraph')) {
    for (const [, label, target] of block.text.matchAll(/(?:^|\s)\[([^\]]+)\]:\s*<?(\S+?)>?(?=\s|$)/g)) {
      definitions.set(label.toLowerCase(), target)
    }
  }

  return definitions
}

/** The inline code spans of a text. */
export const codeSpans = (text) => [...text.matchAll(/`([^`\n]+)`/g)].map((match) => match[1])

const BARE_URL = /\bhttps?:\/\/[^\s<>|`"')\]]*[^\s<>|`"')\].,;:]/g

/** The link targets of a text: inline links and images, reference links, autolinks, HTML and bare URLs. */
export function linksIn(text, definitions = new Map()) {
  const targets = []
  let rest = text.replace(/`[^`\n]+`/g, ' ')

  rest = rest.replace(/!?\[((?:[^\]\\]|\\.)*)\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/g, (whole, label, target) => {
    targets.push(target)

    return ` ${label} `
  })
  rest = rest.replace(/<(?:img|a|source)\s[^>]*?(?:src|href|srcset)="([^"]+)"[^>]*>/g, (whole, target) => {
    targets.push(target)

    return ' '
  })
  rest = rest.replace(/<(https?:\/\/[^>\s]+)>/g, (whole, target) => {
    targets.push(target)

    return ' '
  })
  rest = rest.replace(/(?:^|\s)\[([^\]]+)\]:\s*<?(\S+?)>?(?=\s|$)/g, (whole, label, target) => {
    targets.push(target)

    return ' '
  })
  rest = rest.replace(BARE_URL, (target) => {
    targets.push(target)

    return ' '
  })

  for (const [, label, reference] of rest.matchAll(/\[([^\]]+)\](?:\[([^\]]*)\])?/g)) {
    const target = definitions.get((reference || label).toLowerCase())

    if (target !== undefined && reference !== undefined) {
      targets.push(target)
    }
  }

  return targets
}

/** The words of a text that a reader reads: without its code, its URLs, its HTML and its link targets. */
export function proseOf(text) {
  return text
    .replace(/`[^`\n]+`/g, ' ')
    .replace(/!?\[((?:[^\]\\]|\\.)*)\]\(\s*<?[^)\s>]+>?(?:\s+"[^"]*")?\s*\)/g, ' $1 ')
    .replace(/<[^>]+>/g, ' ')
    .replace(/(?:^|\s)\[[^\]]+\]:\s*\S+/g, ' ')
    .replace(BARE_URL, ' ')
}

// ---------------------------------------------------------------------------------------------------------------
// Figures: dates, times and numbers, each in a form that does not depend on the language it is written in.
// ---------------------------------------------------------------------------------------------------------------

const MONTHS = {
  en: ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'],
  es: ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'],
}

const pad = (value) => String(value).padStart(2, '0')

/**
 * The figures of a text, as strings that do not depend on its language: a date as 2026-11-19 (11-19 without a year),
 * a time as 15:00, and a number with "." as its decimal mark and no thousands separator. A figure with several
 * marks that is not a grouped number (a version, an address, a section) is kept as written.
 */
export function figuresOf(text, language) {
  const figures = []
  const months = MONTHS[language].join('|')
  let rest = ` ${text} `

  const date = (year, month, day) => {
    figures.push([year, pad(month), day === undefined ? undefined : pad(day)].filter((part) => part !== undefined).join('-'))

    return ' '
  }
  const monthNumber = (name) => MONTHS[language].indexOf(name.toLowerCase()) + 1

  rest = rest.replace(/\b(\d{4})-(\d{2})-(\d{2})\b/g, (whole, year, month, day) => date(year, Number(month), Number(day)))

  if (language === 'en') {
    rest = rest.replace(new RegExp(`\\b(${months}) (\\d{1,2})(?:st|nd|rd|th)?(?:, (\\d{4}))?\\b`, 'gi'), (whole, month, day, year) => date(year, monthNumber(month), Number(day)))
    rest = rest.replace(new RegExp(`\\b(\\d{1,2}) (${months})(?: (\\d{4}))?\\b`, 'gi'), (whole, day, month, year) => date(year, monthNumber(month), Number(day)))
    rest = rest.replace(new RegExp(`\\b(${months}) (\\d{4})\\b`, 'gi'), (whole, month, year) => date(year, monthNumber(month)))
  } else {
    rest = rest.replace(new RegExp(`\\b(\\d{1,2}) de (${months})(?: de (\\d{4}))?\\b`, 'gi'), (whole, day, month, year) => date(year, monthNumber(month), Number(day)))
    rest = rest.replace(new RegExp(`\\b(${months}) de (\\d{4})\\b`, 'gi'), (whole, month, year) => date(year, monthNumber(month)))
  }

  // In English a figure takes a plural (the 500s of a status), which reads as the figure itself.
  if (language === 'en') {
    rest = rest.replace(/(\d)s(?![\p{L}\p{N}_])/gu, '$1 ')
  }

  rest = rest.replace(/(?<![\p{L}\p{N}_.,:])(\d{1,2}):(\d{2})(?![\p{L}\p{N}_:])/gu, (whole, hours, minutes) => {
    figures.push(`${pad(hours)}:${minutes}`)

    return ' '
  })

  for (const [token] of rest.matchAll(/(?<![\p{L}\p{N}_.,])\d+(?:[.,]\d+)*(?![\p{L}\p{N}_])/gu)) {
    figures.push(number(token, language))
  }

  return figures
}

/**
 * A number as a string without its language: "6,756" in English and "6.756" in Spanish are both 6756. English text
 * that quotes a figure in Colombian notation, such as the name of a withholding ("ReteFuente 2,5 %"), reads it as
 * Colombian once it cannot be English.
 */
export function number(token, language) {
  const [thousands, decimal] = language === 'en' ? [',', '.'] : ['.', ',']
  const escape = (mark) => `\\${mark}`

  if (new RegExp(`^\\d{1,3}(?:${escape(thousands)}\\d{3})+(?:${escape(decimal)}\\d+)?$`).test(token)) {
    return token.replaceAll(thousands, '').replace(decimal, '.')
  }

  if (new RegExp(`^\\d+${escape(decimal)}\\d+$`).test(token)) {
    return token.replace(decimal, '.')
  }

  if (language === 'en' && /^\d{1,3}(?:\.\d{3})*,\d+$/.test(token)) {
    return number(token, 'es')
  }

  return token
}

// ---------------------------------------------------------------------------------------------------------------
// Code blocks: what must stay the same in each kind of block.
// ---------------------------------------------------------------------------------------------------------------

/** A shell line without its comment: a # at the start of a word, outside quotes. */
export function withoutShellComment(line) {
  let quote = null

  for (let at = 0; at < line.length; at += 1) {
    const char = line[at]

    if (quote !== null) {
      if (char === '\\' && quote === '"') {
        at += 1
      } else if (char === quote) {
        quote = null
      }
    } else if (char === '\\') {
      at += 1
    } else if (char === '"' || char === "'") {
      quote = char
    } else if (char === '#' && (at === 0 || /\s/.test(line[at - 1]))) {
      return line.slice(0, at).trimEnd()
    }
  }

  return line.trimEnd()
}

const SHELL = new Set(['sh', 'bash', 'shell', 'console', 'zsh'])

/** What a code block must keep in its translation, by its language. */
export function codeShape(block) {
  if (SHELL.has(block.language)) {
    return block.code
      .split('\n')
      .map(withoutShellComment)
      .filter((line) => line.trim() !== '')
      .join('\n')
  }

  if (block.language === 'mermaid') {
    return mermaidShape(block.code)
  }

  return block.code.replace(/[ \t]+$/gm, '')
}

/** A Mermaid diagram without its labels: what it draws, not what it says. */
export function mermaidShape(code) {
  const lines = code.split('\n').filter((line) => line.trim() !== '' && !line.trim().startsWith('%%'))
  const kind = (lines[0] ?? '').trim().split(/\s+/)[0]

  return lines
    .map((line, index) => {
      let shape = line.trim().replace(/"[^"]*"/g, '""')

      if (index === 0) {
        return shape
      }

      if (kind === 'sequenceDiagram') {
        shape = shape.replace(/^(participant|actor)\s+(\S+)\s+as\s+.*$/, '$1 $2 as').replace(/^(Note\s+(?:over|left of|right of)\s+[^:]+):.*$/, '$1:').replace(/^([^:]*?(?:-{1,2}>>?|-{1,2}[x)]|-{1,2}>)[^:]*):.*$/, '$1:')
      } else if (kind.startsWith('stateDiagram')) {
        shape = shape.replace(/:.*$/, '').replace(/\[\*\]/g, '§').replace(/[\p{L}\p{N}_]+/gu, (word) => (['direction', 'LR', 'RL', 'TB', 'BT', 'state', 'note'].includes(word) ? word : 'w')).replace(/§/g, '[*]')
      } else if (kind === 'erDiagram') {
        shape = shape.replace(/\s*:\s*""\s*$/, ' : ""')
      } else {
        shape = shape.replace(/\[\[[^\]]*\]\]/g, '[[]]').replace(/\(\([^)]*\)\)/g, '(())').replace(/\[[^\]]*\]/g, '[]').replace(/\([^)]*\)/g, '()').replace(/\{[^}]*\}/g, '{}').replace(/\|[^|]*\|/g, '||')
      }

      return shape.replace(/\s+/g, ' ')
    })
    .join('\n')
}

// ---------------------------------------------------------------------------------------------------------------
// The comparison of a document and its translation.
// ---------------------------------------------------------------------------------------------------------------

/** The language line right under the title of a Markdown document. */
export const LANGUAGE_LINE = /^(\*\*English\*\* · \[Español\]\(([^)\s]+)\)|\[English\]\(([^)\s]+)\) · \*\*Español\*\*)$/

/** The relative link from one file of the repository to another. */
export function relativeLink(from, to) {
  const link = posix.relative(posix.dirname(from), to)

  return link === '' ? posix.basename(to) : link
}

/** The language line a document must carry, given its path and its pair's. */
export function languageLineFor(path, pair, spanish) {
  return spanish ? `[English](${relativeLink(path, pair)}) · **Español**` : `**English** · [Español](${relativeLink(path, pair)})`
}

/** A section of a document: the blocks under one heading, up to the next heading. */
function sectionsOf(blocks) {
  const sections = [{ heading: null, blocks: [] }]

  for (const block of blocks) {
    if (block.type === 'heading') {
      sections.push({ heading: block, blocks: [] })
    } else {
      sections.at(-1).blocks.push(block)
    }
  }

  return sections
}

/** The slug GitHub gives a heading (as check.mjs computes it). */
export function slugOf(heading) {
  return heading
    .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
    .replace(/<[^>]+>/g, '')
    .replace(/[`*]/g, '')
    .trim()
    .toLowerCase()
    .replace(/[^\p{L}\p{M}\p{N}\p{Pc} -]/gu, '')
    .replace(/ /g, '-')
}

/** The anchors of a document's headings, in order, numbered as GitHub numbers repeated ones. */
export function anchorsOf(blocks) {
  const seen = new Map()

  return blocks
    .filter((block) => block.type === 'heading')
    .map((block) => {
      const slug = slugOf(block.text)
      const count = seen.get(slug) ?? 0
      seen.set(slug, count + 1)

      return count === 0 ? slug : `${slug}-${count}`
    })
}

/** Counts of what one side has and the other has not, as [value, how many more]. */
function difference(left, right) {
  const counts = new Map()
  left.forEach((value) => counts.set(value, (counts.get(value) ?? 0) + 1))
  right.forEach((value) => counts.set(value, (counts.get(value) ?? 0) - 1))

  return [...counts].filter(([, count]) => count !== 0)
}

/**
 * The exceptions of translations.json, with what each one allows: a difference of one kind (code-block,
 * inline-code, link, figure, structure, english, term) in one translation, matched by its value.
 */
export class Exceptions {
  constructor(list = []) {
    this.list = list.map((exception) => ({ ...exception, used: 0 }))
  }

  /** Whether a difference is declared, and the exception that declares it is counted as used. */
  allows(translation, kind, value) {
    const exception = this.list.find((candidate) => candidate.translation === translation && candidate.kind === kind && (candidate.value === value || (candidate.prefix !== undefined && String(value).startsWith(candidate.prefix))))

    if (exception === undefined) {
      return false
    }

    exception.used += 1

    return true
  }

  /** The URL a link of the original takes in the translation, when an exception gives one. */
  mapped(translation, target, section) {
    const exception = this.list.find((candidate) => candidate.translation === translation && candidate.kind === 'link-map' && candidate.original === target && (candidate.under === undefined || candidate.under === section))

    if (exception === undefined) {
      return null
    }

    exception.used += 1

    return exception.value
  }

  /** The problems of the list itself: an exception without its reason, or one that matched nothing. */
  problems(source) {
    return this.list.flatMap((exception) => [
      ...(typeof exception.reason === 'string' && exception.reason.length >= 20 ? [] : [`${source}: the exception ${exception.kind} ${JSON.stringify(exception.value)} of ${exception.translation} gives no reason`]),
      ...(exception.used > 0 ? [] : [`${source}: the exception ${exception.kind} ${JSON.stringify(exception.value)} of ${exception.translation} matches nothing: remove it`]),
    ])
  }
}

/**
 * The problems of a translation against its original (both Markdown): structure, code, inline code, links and
 * figures, section by section. `pairOf` gives the Spanish pair of a path of the repository (or null), `exists` says
 * whether a path exists, and `anchorsFor` gives the anchors of a document of the repository.
 */
export function compareMarkdown({ original, translation, originalText, translationText, pairOf, exists, anchorsFor, exceptions }) {
  const problems = []
  const left = parse(originalText)
  const right = parse(translationText)
  const leftHeadings = left.filter((block) => block.type === 'heading')
  const rightHeadings = right.filter((block) => block.type === 'heading')
  const counts = { code: 0, inline: 0, links: 0, figures: 0 }
  const at = (block) => `${translation}:${block.line}`

  if (leftHeadings.map((heading) => heading.level).join() !== rightHeadings.map((heading) => heading.level).join()) {
    const index = leftHeadings.findIndex((heading, position) => rightHeadings[position]?.level !== heading.level)
    const missing = index === -1 ? rightHeadings.length : index

    if (!exceptions.allows(translation, 'structure', 'headings')) {
      problems.push(
        `${translation}: its headings are not those of ${original} (${leftHeadings.length} there, ${rightHeadings.length} here); the first that differs is "${'#'.repeat(leftHeadings[missing]?.level ?? 0)} ${leftHeadings[missing]?.text ?? '(none)'}" of the original, line ${leftHeadings[missing]?.line ?? '?'}, against "${rightHeadings[missing]?.text ?? '(none)'}"`,
      )

      return { problems, counts }
    }
  }

  // The marks of the examples make docs-examples runs, and of the outputs it compares, in order.
  const marks = (blocks) => blocks.filter((block) => block.type === 'comment' && /^<!-- (example(?:: [a-z]+)?|output) -->$/.test(block.text.trim())).map((block) => block.text.trim())

  if (marks(left).join() !== marks(right).join() && !exceptions.allows(translation, 'structure', 'examples')) {
    problems.push(`${translation}: its marks of examples and outputs (${marks(right).length}) are not those of ${original} (${marks(left).length}), in the same order`)
  }

  const leftDefinitions = definitionsOf(left)
  const rightDefinitions = definitionsOf(right)
  const leftSections = sectionsOf(left)
  const rightSections = sectionsOf(right)

  /** Where a link of the original must go in the translation, as a path of the repository with its anchor. */
  const expected = (target, section) => {
    const mapped = exceptions.mapped(translation, target, section)

    if (mapped !== null) {
      return resolve(translation, mapped)
    }

    const resolved = resolve(original, target)

    if (resolved.external) {
      return resolved
    }

    const pair = pairOf(resolved.path)
    const image = /^docs\/assets\/[^/]+\.(png|gif|jpg|svg)$/.exec(resolved.path) !== null ? resolved.path.replace(/^docs\/assets\//, 'docs/assets/es/') : null

    if (image !== null && exists(image)) {
      return { ...resolved, path: image }
    }

    if (pair === null) {
      return resolved
    }

    if (resolved.anchor === '') {
      return { ...resolved, path: pair }
    }

    const from = anchorsFor(resolved.path)
    const to = anchorsFor(pair)
    const index = from.indexOf(resolved.anchor)

    return { ...resolved, path: pair, anchor: index === -1 || to[index] === undefined ? resolved.anchor : to[index] }
  }

  const show = (resolved) => (resolved.external ? resolved.url : `${resolved.path}${resolved.anchor === '' ? '' : `#${resolved.anchor}`}`)

  for (const [index, section] of leftSections.entries()) {
    const other = rightSections[index]
    const name = section.heading === null ? 'before the first heading' : `under "${other.heading.text}" (line ${other.heading.line})`
    const lists = (blocks) => {
      const all = []
      let current = null

      for (const block of blocks) {
        if (block.type === 'item') {
          if (current === null) {
            current = []
            all.push(current)
          }

          current.push(block.indent)
        } else {
          current = null
        }
      }

      return all.map((list) => list.map((indent) => (indent >= 2 ? 1 : 0)).join(''))
    }

    // The structure: the rows of each table, the items of each list.
    const leftTables = section.blocks.filter((block) => block.type === 'table').map((block) => block.rows.length)
    const rightTables = other.blocks.filter((block) => block.type === 'table').map((block) => block.rows.length)

    if (leftTables.join() !== rightTables.join() && !exceptions.allows(translation, 'structure', `tables ${name}`)) {
      problems.push(`${translation}: the tables ${name} have ${rightTables.join(', ') || 'no'} rows; those of ${original} have ${leftTables.join(', ') || 'no'}`)
    }

    const leftLists = lists(section.blocks)
    const rightLists = lists(other.blocks)

    if (leftLists.join() !== rightLists.join() && !exceptions.allows(translation, 'structure', `lists ${name}`)) {
      problems.push(`${translation}: the lists ${name} have ${rightLists.map((list) => list.length).join(', ') || 'no'} items; those of ${original} have ${leftLists.map((list) => list.length).join(', ') || 'no'} (nesting included)`)
    }

    // The code blocks, in order.
    const leftCode = section.blocks.filter((block) => block.type === 'code')
    const rightCode = other.blocks.filter((block) => block.type === 'code')

    if (leftCode.length !== rightCode.length) {
      if (!exceptions.allows(translation, 'code-block', `count ${name}`)) {
        problems.push(`${translation}: ${rightCode.length} code blocks ${name}; ${original} has ${leftCode.length}`)
      }
    } else {
      for (const [position, block] of leftCode.entries()) {
        counts.code += 1
        const theirs = rightCode[position]

        if ((block.language !== theirs.language || codeShape(block) !== codeShape(theirs)) && !exceptions.allows(translation, 'code-block', `${theirs.line}`)) {
          const mine = codeShape(block).split('\n')
          const yours = codeShape(theirs).split('\n')
          const line = mine.findIndex((text, row) => text !== yours[row])
          problems.push(
            `${at(theirs)}: this ${theirs.language || 'text'} block is not the one of ${original}:${block.line}${block.language !== theirs.language ? ` (language ${block.language} there)` : `; first difference: ${JSON.stringify(mine[line] ?? '(end)')} there, ${JSON.stringify(yours[line] ?? '(end)')} here`}`,
          )
        }
      }
    }

    // The inline code, the links and the figures of the prose.
    const prose = (blocks) => blocks.filter((block) => block.type !== 'code' && block.type !== 'comment' && !(block.type === 'paragraph' && LANGUAGE_LINE.test(block.text)))
    const leftProse = prose([...(section.heading === null ? [] : [section.heading]), ...section.blocks])
    const rightProse = prose([...(other.heading === null ? [] : [other.heading]), ...other.blocks])
    const texts = (blocks) => blocks.flatMap(textsOf)

    const leftSpans = texts(leftProse).flatMap(codeSpans)
    const rightSpans = texts(rightProse).flatMap(codeSpans)
    counts.inline += leftSpans.length

    for (const [value, count] of difference(leftSpans, rightSpans)) {
      if (!exceptions.allows(translation, 'inline-code', value)) {
        problems.push(`${translation}: ${count > 0 ? `the inline code \`${value}\` of ${original} is missing` : `the inline code \`${value}\` is not in ${original}`} ${name}${Math.abs(count) > 1 ? ` (${Math.abs(count)} times)` : ''}`)
      }
    }

    const leftLinks = texts(leftProse)
      .flatMap((text) => linksIn(text, leftDefinitions))
      .map((target) => show(expected(target, section.heading?.text)))
    const rightLinks = texts(rightProse)
      .flatMap((text) => linksIn(text, rightDefinitions))
      .map((target) => show(resolve(translation, target)))
    counts.links += leftLinks.length

    for (const [value, count] of difference(leftLinks, rightLinks)) {
      if (!exceptions.allows(translation, 'link', value)) {
        problems.push(`${translation}: ${count > 0 ? `no link to ${value}, which ${original} links` : `a link to ${value}, which ${original} does not have`} ${name}`)
      }
    }

    const leftFigures = texts(leftProse).flatMap((text) => figuresOf(proseOf(text), 'en'))
    const rightFigures = texts(rightProse).flatMap((text) => figuresOf(proseOf(text), 'es'))
    counts.figures += leftFigures.length

    for (const [value, count] of difference(leftFigures, rightFigures)) {
      if (!exceptions.allows(translation, 'figure', value)) {
        problems.push(`${translation}: ${count > 0 ? `the figure ${value} of ${original} is missing` : `the figure ${value} is not in ${original}`} ${name}${Math.abs(count) > 1 ? ` (${Math.abs(count)} times)` : ''}`)
      }
    }
  }

  return { problems, counts }
}

/** A link target as a path of the repository and an anchor, or an external URL. */
export function resolve(from, target) {
  if (/^[a-z][a-z0-9+.-]*:/i.test(target) || target.startsWith('//')) {
    return { external: true, url: target }
  }

  const [path, anchor = ''] = target.split('#', 2)
  const resolved = path === '' ? from : posix.normalize(posix.join(posix.dirname(from), decodeURIComponent(path)))

  return { external: false, path: resolved.replace(/\/$/, ''), anchor }
}

// ---------------------------------------------------------------------------------------------------------------
// NOTICE, which is plain text: its numbered sections, the paths and addresses it names, its figures, and the
// notices of others it quotes, word for word.
// ---------------------------------------------------------------------------------------------------------------

/** The paragraphs of a plain text, each with whether it is quoted (its lines start with >) and its first line. */
export function paragraphsOf(text) {
  const paragraphs = []
  let current = null

  for (const [index, line] of text.replace(/\r/g, '').split('\n').entries()) {
    if (line.trim() === '') {
      current = null
      continue
    }

    const quoted = /^\s*>/.test(line)
    const content = quoted ? line.replace(/^\s*>\s?/, '') : line

    if (current === null || current.quoted !== quoted) {
      current = { quoted, lines: [], line: index + 1 }
      paragraphs.push(current)
    }

    current.lines.push(content)
  }

  return paragraphs.map((paragraph) => ({ ...paragraph, text: paragraph.lines.map((line) => line.trim()).join(' ') }))
}

const PATH_LINE = /^(backend|frontend|simulator|infra|scripts|docs|e2e)\/\S*$/

export function compareNotice({ originalText, translationText, translation = 'NOTICE.es.txt', exceptions = new Exceptions() }) {
  const problems = []
  const left = paragraphsOf(originalText)
  // The stamp line is the translation's own: it names its original's SHA-256, which the original does not hold.
  const right = paragraphsOf(translationText).filter((paragraph) => !isStamp(paragraph.text))
  const whole = new Set(left.map((paragraph) => paragraph.text.replace(/\s+/g, ' ')))
  const lines = (paragraphs) => paragraphs.flatMap((paragraph) => paragraph.lines.map((line) => line.trim()))
  // A numbered section starts at the margin; the numbered items of a quoted license are indented or quoted.
  const sectionsIn = (paragraphs) =>
    paragraphs
      .filter((paragraph) => !paragraph.quoted)
      .flatMap((paragraph) => paragraph.lines)
      .filter((line) => /^\d+\.\s+\S/.test(line))
      .map((line) => line.split('.')[0])
  const pathsIn = (paragraphs) => lines(paragraphs).filter((line) => PATH_LINE.test(line))

  for (const paragraph of right.filter((candidate) => candidate.quoted)) {
    if (!whole.has(paragraph.text.replace(/\s+/g, ' '))) {
      problems.push(`${translation}:${paragraph.line}: a quoted notice that NOTICE does not have word for word: "${paragraph.text.slice(0, 80)}…"`)
    }
  }

  const leftSections = sectionsIn(left)
  const rightSections = sectionsIn(right)

  if (leftSections.join() !== rightSections.join()) {
    problems.push(`${translation}: its numbered sections are ${rightSections.join(', ')}; those of NOTICE are ${leftSections.join(', ')}`)
  }

  for (const [value, count] of difference(pathsIn(left), pathsIn(right))) {
    problems.push(`${translation}: ${count > 0 ? `does not list ${value}, which NOTICE lists` : `lists ${value}, which NOTICE does not`}`)
  }

  const urls = (paragraphs) => paragraphs.flatMap((paragraph) => paragraph.text.match(BARE_URL) ?? [])

  for (const [value, count] of difference(urls(left), urls(right))) {
    if (!exceptions.allows(translation, 'link', value)) {
      problems.push(`${translation}: ${count > 0 ? `does not name ${value}, which NOTICE names` : `names ${value}, which NOTICE does not`}`)
    }
  }

  const figures = (paragraphs, language) =>
    paragraphs
      .filter((paragraph) => !paragraph.lines.every((line) => PATH_LINE.test(line.trim())))
      .flatMap((paragraph) => figuresOf(proseOf(paragraph.text).replace(/\b\S+\/\S+/g, ' '), paragraph.quoted ? 'en' : language))

  for (const [value, count] of difference(figures(left, 'en'), figures(right, 'es'))) {
    if (!exceptions.allows(translation, 'figure', value)) {
      problems.push(`${translation}: ${count > 0 ? `the figure ${value} of NOTICE is missing` : `the figure ${value} is not in NOTICE`}`)
    }
  }

  return problems
}

// ---------------------------------------------------------------------------------------------------------------
// Spanish that is not half done: no paragraph left in English, no term the glossary replaces.
// ---------------------------------------------------------------------------------------------------------------

const ENGLISH = new Set(
  'the and of to is are was were be been being with for that this these those it its by on from or an as at which when where whose has have had not but if then than into each every only also can will would should must does do did after before there their they them what who how why all any both other same such too under until up very while would your you we our about over between through without within upon because just more most'.split(
    ' ',
  ),
)
const SPANISH = new Set(
  'el la los las del y que en un una unos unas por para con es son se su sus al lo como más pero si cuando donde cada sin sobre entre también solo ya hay está están este esta estos estas ese esa eso esos esas otro otra otros otras todo toda todos todas muy nada desde hasta porque cual cuales quien qué cómo dónde mismo misma ni tras según durante mientras aquí ahí allí le les nos ser fue era han ha puede pueden debe deben tiene tienen hace hacen va van'.split(
    ' ',
  ),
)

/**
 * Whether a text reads as English: enough of its words are English ones and few are Spanish. A name with a digit
 * (BSD-3-Clause, GPL-2.0-only) and a word in capitals (an acronym, or an operator of an SPDX expression: OR, AND) are
 * not prose.
 */
export function looksEnglish(text) {
  const words = proseOf(text)
    .replace(/\S*\d\S*/g, ' ')
    .replace(/\b[A-Z]{2,}\b/g, ' ')
    .toLowerCase()
    .match(/\p{L}+(?:'\p{L}+)?/gu) ?? []
  const english = words.filter((word) => ENGLISH.has(word)).length
  const spanish = words.filter((word) => SPANISH.has(word)).length

  return words.length >= 5 && english >= 3 && english > 2 * spanish
}

/** The prose blocks of a Spanish document that read as English, a table column headed "Inglés" aside. */
export function englishIn(blocks) {
  const found = []

  for (const block of blocks) {
    if (block.type === 'table') {
      const english = block.rows[0]?.findIndex((cell) => cell === 'Inglés') ?? -1

      block.rows.forEach((row, index) => {
        row.forEach((cell, column) => {
          if (column !== english && looksEnglish(cell)) {
            found.push({ line: block.line + index + (index > 0 ? 1 : 0), text: cell })
          }
        })
      })
    } else if (['heading', 'paragraph', 'item', 'quote'].includes(block.type) && !LANGUAGE_LINE.test(block.text) && looksEnglish(block.text)) {
      found.push({ line: block.line, text: block.text })
    }
  }

  return found
}

/**
 * The glossary's replaced terms, from the tables of docs/es/README.md whose header is Inglés | Español | En vez de:
 * each variant of the third column (separated by commas) with the term that replaces it.
 */
export function glossaryOf(text) {
  const tables = parse(text).filter((block) => block.type === 'table' && block.rows[0]?.join('|') === 'Inglés|Español|En vez de')

  return tables.flatMap((table) => table.rows.slice(1)).flatMap(([english, spanish, avoid = '']) =>
    avoid
      .split(',')
      .map((variant) => variant.trim())
      .filter((variant) => variant !== '' && variant !== '—')
      .map((variant) => ({ variant, term: spanish, english, pattern: variantPattern(variant) })),
  )
}

/** A variant as a pattern: its words in order, each also in the plural, as whole words, in any case. */
export function variantPattern(variant) {
  const words = variant.split(/\s+/).map((word) => `${word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?:s|es)?`)

  return new RegExp(`(?<![\\p{L}\\p{N}_/.-])${words.join('\\s+')}(?![\\p{L}\\p{N}_/-])`, 'giu')
}

/** The places where a Spanish text uses a variant the glossary replaces. */
export function replacedTermsIn(blocks, glossary) {
  const found = []

  for (const block of blocks) {
    const texts = block.type === 'table' ? block.rows.slice(1).map((row) => row.filter((cell, column) => !(block.rows[0]?.[column] === 'Inglés' || block.rows[0]?.[column] === 'En vez de')).join(' | ')) : textsOf(block)

    for (const text of texts) {
      const prose = proseOf(text)

      for (const entry of glossary) {
        for (const match of prose.matchAll(entry.pattern)) {
          found.push({ line: block.line, variant: match[0], term: entry.term })
        }
      }
    }
  }

  return found
}

// ---------------------------------------------------------------------------------------------------------------
// The whole check, over a repository.
// ---------------------------------------------------------------------------------------------------------------

/**
 * Every problem of the translations of a repository, given its root and its files (git's list), with the counts
 * of what was compared.
 */
export function checkTranslations(root, files, { exceptions: list = loadExceptions(root) } = {}) {
  const exceptions = new Exceptions(list)
  const read = (path) => readFileSync(join(root, path), 'utf8')
  const exists = (path) => existsSync(join(root, path))
  const { pairs, problems } = pairsOf(files)
  const counts = { pairs: pairs.length, generated: pairs.filter((pair) => pair.generated).length, stamped: 0, code: 0, inline: 0, links: 0, figures: 0, english: 0, terms: 0 }
  const anchorCache = new Map()
  const anchorsFor = (path) => anchorCache.get(path) ?? anchorCache.set(path, exists(path) && path.endsWith('.md') ? anchorsOf(parse(read(path))) : []).get(path)
  const pairOf = (path) => {
    const pair = translationOf(path)

    return pair !== null && exists(pair) ? pair : null
  }
  const glossary = exists('docs/es/README.md') ? glossaryOf(read('docs/es/README.md')) : []

  for (const { original, translation, generated } of pairs) {
    const originalText = read(original)
    const translationText = read(translation)

    // The stamp: the translation follows the original as it is now.
    if (!generated) {
      const stamp = stampOf(translationText)

      if (stamp === null) {
        problems.push(`${translation}: no stamp of its original (${original}): translate it, then run make docs-stamp`)
      } else if (stamp !== sha256(originalText)) {
        problems.push(`${translation}: translates an older ${original}: bring the translation up to date, then run make docs-stamp`)
      } else {
        counts.stamped += 1
      }
    }

    problems.push(...pairProblems({ root, original, translation, originalText, translationText, pairOf, exists, anchorsFor, exceptions, glossary, counts }))
  }

  problems.push(...exceptions.problems('e2e/docs/translations.json'))

  return { problems, counts }
}

/** The problems of one pair, the stamp aside: language lines, parity, English left and replaced terms. */
export function pairProblems({ original, translation, originalText, translationText, pairOf, exists, anchorsFor, exceptions, glossary, counts = {} }) {
  const problems = []
  const add = (key, value) => (counts[key] = (counts[key] ?? 0) + value)

  if (original === 'NOTICE') {
    if (!originalText.split('\n').slice(0, 8).includes(NOTICE_LINES.original)) {
      problems.push(`NOTICE: its first lines do not name its translation: "${NOTICE_LINES.original}"`)
    }

    if (!translationText.split('\n').slice(0, 8).includes(NOTICE_LINES.translation)) {
      problems.push(`${translation}: its first lines do not name NOTICE: "${NOTICE_LINES.translation}"`)
    }

    problems.push(...compareNotice({ originalText, translationText, translation, exceptions }))

    for (const paragraph of paragraphsOf(translationText).filter((candidate) => !candidate.quoted && !isStamp(candidate.text) && !candidate.lines.every((line) => PATH_LINE.test(line.trim())))) {
      if (looksEnglish(paragraph.text) && !exceptions.allows(translation, 'english', paragraph.text)) {
        add('english', 1)
        problems.push(`${translation}:${paragraph.line}: a paragraph in English that is not a quoted notice (quote one with "> "): "${paragraph.text.slice(0, 80)}…"`)
      }
    }

    return problems
  }

  // The language lines: right under the title, each file names its pair.
  for (const [path, text, pair, spanish] of [
    [original, originalText, translation, false],
    [translation, translationText, original, true],
  ]) {
    const blocks = parse(text)
    const title = blocks.findIndex((block) => block.type === 'heading')
    const next = blocks.slice(title + 1).find((block) => block.type !== 'comment')
    const line = languageLineFor(path, pair, spanish)

    if (title !== 0 || next?.type !== 'paragraph' || next.text !== line) {
      problems.push(`${path}: the line right under its title is not "${line}"`)
    }
  }

  const { problems: parity, counts: compared } = compareMarkdown({ original, translation, originalText, translationText, pairOf, exists, anchorsFor, exceptions })
  problems.push(...parity)
  Object.entries(compared).forEach(([key, value]) => add(key, value))

  const blocks = parse(translationText)

  for (const block of englishIn(blocks)) {
    if (!exceptions.allows(translation, 'english', block.text)) {
      add('english', 1)
      problems.push(`${translation}:${block.line}: still in English: "${block.text.slice(0, 90)}${block.text.length > 90 ? '…' : ''}"`)
    }
  }

  for (const found of replacedTermsIn(blocks, glossary)) {
    if (!exceptions.allows(translation, 'term', found.variant.toLowerCase())) {
      add('terms', 1)
      problems.push(`${translation}:${found.line}: "${found.variant}", which the glossary replaces with "${found.term}" (docs/es/README.md)`)
    }
  }

  return problems
}

/** The exceptions of translations.json, next to this file. */
export function loadExceptions(root) {
  const path = join(root, 'e2e/docs/translations.json')

  return existsSync(path) ? JSON.parse(readFileSync(path, 'utf8')).exceptions : []
}
