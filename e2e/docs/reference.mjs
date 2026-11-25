// The API reference in Spanish (make docs-check, through check.mjs): openapi.es.json, the document the backend serves at
// /docs/api/es, against openapi.json and the catalog it is translated with, backend/resources/api-reference/es.json
// (App\Support\ApiDocs\ReferenceCatalog). A backend test holds both files to what the code generates
// (ApiReferenceTest); this check holds the Spanish to the English:
//   - the same contract: without the texts a person reads, the two documents are the same, paths, operations,
//     parameters, schemas, enum values and examples included;
//   - nothing left untranslated: every block of an English text (a paragraph, a list item, a table row or a heading,
//     with its lines joined, as ReferenceCatalog splits it) has its translation, or is declared the same in both
//     languages with its reason, and every entry of the catalog matches a block of the English reference;
//   - each translation keeps its original's inline code, links and figures, and passes the checks of the Spanish
//     documentation: nothing that reads as English, no term the glossary of docs/es/README.md replaces;
//   - openapi.es.json holds the catalog's translation of every text, and each reference's first line links to the
//     other one.
// No dependencies: make test-tools tests it (reference.test.mjs).
import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { codeSpans, figuresOf, glossaryOf, linksIn, looksEnglish, proseOf, replacedTermsIn } from './translations.mjs'

/** The files the check reads, from the root of the repository. */
export const FILES = { english: 'openapi.json', spanish: 'openapi.es.json', catalog: 'backend/resources/api-reference/es.json' }

/** The keys whose values are data, never a text: examples, defaults, enum values and constants. */
const DATA = new Set(['example', 'examples', 'default', 'enum', 'const'])

/** The first line of each reference's description: its language, and a link to the other reference. */
export const languageLine = {
  english: (origin) => `**English** · [Español](${origin}/docs/api/es)`,
  spanish: (origin) => `[English](${origin}/docs/api) · **Español**`,
}

const escapeToken = (token) => token.replaceAll('~', '~0').replaceAll('/', '~1')

/**
 * A copy of an OpenAPI document with each text a person reads replaced by what `replace` gives for it: each summary
 * and description (kind text), and each name of a group, where the document declares it and where an operation names
 * it (kind tag). Data (examples, defaults, enum values, constants) is never a text, and neither is the name of a field.
 */
export function mapTexts(document, replace) {
  const walk = (node, pointer, fields = false) => {
    if (Array.isArray(node)) {
      return node.map((item, index) => walk(item, `${pointer}/${index}`))
    }

    if (node === null || typeof node !== 'object') {
      return node
    }

    const copy = {}

    for (const [key, value] of Object.entries(node)) {
      const at = `${pointer}/${escapeToken(key)}`

      if (fields) {
        copy[key] = walk(value, at)
      } else if (DATA.has(key)) {
        copy[key] = value
      } else if ((key === 'description' || key === 'summary') && typeof value === 'string') {
        copy[key] = value === '' ? value : replace({ pointer: at, text: value, kind: 'text' })
      } else if (key === 'name' && /^\/tags\/\d+$/.test(pointer) && typeof value === 'string') {
        copy[key] = replace({ pointer: at, text: value, kind: 'tag' })
      } else if (key === 'tags' && pointer !== '' && Array.isArray(value)) {
        copy[key] = value.map((tag, index) => (typeof tag === 'string' ? replace({ pointer: `${at}/${index}`, text: tag, kind: 'tag' }) : tag))
      } else {
        copy[key] = walk(value, at, key === 'properties')
      }
    }

    return copy
  }

  return walk(document, '')
}

/** The texts a person reads in a document, each with its JSON pointer and its kind. */
export function textsOf(document) {
  const texts = []
  mapTexts(document, (text) => {
    texts.push(text)

    return text.text
  })

  return texts
}

/**
 * The units of a block of text, as ReferenceCatalog reads them: a heading, a table row, a list item with the lines that
 * continue it, or the lines of a paragraph; each with its marks (the # of a heading, the - of an item, the outer | of a
 * row) and its text, the lines joined by a space.
 */
export function unitsOf(block) {
  const units = []
  let continues = false

  for (const line of block.split('\n')) {
    const heading = /^(#{1,6} +)(.*)$/.exec(line)
    const row = heading === null ? /^([ \t]*\|[ \t]*)(.*?)([ \t]*\|?[ \t]*)$/.exec(line) : null
    const item = heading === null && row === null ? /^([ \t]*(?:[-*+]|\d+[.)]) +)(.*)$/.exec(line) : null

    if (heading !== null) {
      units.push({ prefix: heading[1], lines: [heading[2]], suffix: '' })
      continues = false
    } else if (row !== null) {
      units.push({ prefix: row[1], lines: [row[2]], suffix: row[3] })
      continues = false
    } else if (item !== null) {
      units.push({ prefix: item[1], lines: [item[2]], suffix: '' })
      continues = true
    } else if (continues && units.length > 0) {
      units.at(-1).lines.push(line)
    } else {
      units.push({ prefix: '', lines: [line], suffix: '' })
      continues = true
    }
  }

  return units.map(({ prefix, lines, suffix }) => ({ prefix, text: lines.map((line) => line.trim()).join(' ').trim(), suffix }))
}

/** Whether a unit is a block to translate: one with a letter, so not a table's |---|, nor an empty row. */
const hasLetter = (text) => /\p{L}/u.test(text)

/** The blocks of a text that the catalog translates: each unit with a letter, of each part between blank lines. */
export function blocksOf(text) {
  return text
    .split(/\n[ \t]*\n/)
    .flatMap(unitsOf)
    .map((unit) => unit.text)
    .filter(hasLetter)
}

/** A text in Spanish, as ReferenceCatalog::translate() gives it: each unit through the catalog, or as it is. */
export function translate(text, blocks) {
  return text
    .split(/\n[ \t]*\n/)
    .map((part) =>
      unitsOf(part)
        .map(({ prefix, text: unit, suffix }) => `${prefix}${hasLetter(unit) ? (blocks.get(unit) ?? unit) : unit}${suffix}`)
        .join('\n'),
    )
    .join('\n\n')
}

/** The origin both language lines link to: the one of the document's server, as the backend generates them. */
export function originOf(document) {
  try {
    return new URL(document.servers?.[0]?.url ?? '').origin
  } catch {
    return null
  }
}

/** A multiset difference, as [value, how many more on the left]. */
function difference(left, right) {
  const counts = new Map()
  left.forEach((value) => counts.set(value, (counts.get(value) ?? 0) + 1))
  right.forEach((value) => counts.set(value, (counts.get(value) ?? 0) - 1))

  return [...counts].filter(([, count]) => count !== 0)
}

const show = (text) => JSON.stringify(text.length > 90 ? `${text.slice(0, 90)}…` : text)

/** The first pointer where two JSON values differ, the order of their keys included, or null when they are the same. */
export function firstDifference(left, right, pointer = '') {
  if (typeof left !== typeof right || Array.isArray(left) !== Array.isArray(right) || (left === null) !== (right === null)) {
    return pointer || '/'
  }

  if (left === null || typeof left !== 'object') {
    return left === right ? null : pointer || '/'
  }

  const keys = (value) => (Array.isArray(value) ? value.map((item, index) => String(index)) : Object.keys(value))
  const [leftKeys, rightKeys] = [keys(left), keys(right)]

  for (let index = 0; index < Math.max(leftKeys.length, rightKeys.length); index += 1) {
    const key = leftKeys[index]

    if (key !== rightKeys[index]) {
      return `${pointer}/${escapeToken(key ?? rightKeys[index])}`
    }

    const found = firstDifference(left[key], right[key], `${pointer}/${escapeToken(key)}`)

    if (found !== null) {
      return found
    }
  }

  return null
}

/**
 * Every problem of the Spanish reference: `english` and `spanish` are the two documents, `catalog` the parsed
 * catalog and `glossary` the replaced terms of docs/es/README.md (glossaryOf). With the counts of what was compared.
 */
export function referenceProblems({ english, spanish, catalog, glossary = [] }) {
  const problems = []
  const counts = { texts: 0, blocks: 0, translated: 0, same: 0, inline: 0, links: 0, figures: 0 }
  const at = FILES.catalog

  // The catalog: its parts, each translation a string of its own and each block that reads the same with its reason.
  const translations = catalog?.translations
  const same = catalog?.same
  const title = catalog?.page?.title

  if (typeof translations !== 'object' || translations === null || typeof same !== 'object' || same === null || typeof title !== 'string') {
    return { problems: [`${at}: it needs translations and same, as objects, and page.title`], counts }
  }

  // Each reference names both languages on its first line and links to the other one.
  const origin = originOf(english)
  const lines = {
    english: (english.info?.description ?? '').split('\n')[0],
    spanish: (spanish.info?.description ?? '').split('\n')[0],
  }

  for (const [language, document] of [
    ['english', FILES.english],
    ['spanish', FILES.spanish],
  ]) {
    if (origin === null || lines[language] !== languageLine[language](origin)) {
      problems.push(`${document}: the first line of info.description is not ${show(origin === null ? '(no server to take the origin from)' : languageLine[language](origin))}, but ${show(lines[language])}`)
    }
  }

  // The same contract: without their texts, and with each group by its place, the two documents are one.
  const order = (document) => new Map((document.tags ?? []).map((tag, index) => [tag.name, index]))
  const contract = (document) => {
    const places = order(document)

    return mapTexts(document, ({ kind, text }) => (kind === 'tag' ? `group ${places.get(text) ?? '?'}` : '(text)'))
  }
  const changed = firstDifference(contract(english), contract(spanish))

  if (changed !== null) {
    problems.push(`${FILES.spanish}: not the contract of ${FILES.english}: they differ at ${changed}, which is no text for people`)
  }

  // The blocks of the English reference, each with the first place it was found.
  const withoutLine = (text, line) => (text.startsWith(`${line}\n\n`) ? text.slice(line.length + 2) : text)
  const englishTexts = textsOf(english)
  const found = new Map()

  for (const { pointer, text } of englishTexts) {
    const own = pointer === '/info/description' ? withoutLine(text, lines.english) : text

    for (const block of blocksOf(own)) {
      if (!found.has(block)) {
        found.set(block, pointer)
      }
    }
  }

  counts.texts = new Set(englishTexts.map(({ text }) => text)).size
  counts.blocks = found.size

  for (const [block, pointer] of found) {
    if (!(block in translations) && !(block in same)) {
      problems.push(`${at}: no translation of ${show(block)} (${FILES.english}#${pointer}): add it to translations, or to same with the reason it reads the same in Spanish`)
    }
  }

  for (const block of Object.keys(same)) {
    counts.same += 1

    if (!found.has(block)) {
      problems.push(`${at}: same declares ${show(block)}, which no text of ${FILES.english} holds any more: remove it`)
    }

    if (typeof same[block] !== 'string' || same[block].trim().length < 20) {
      problems.push(`${at}: same declares ${show(block)} without its reason`)
    }

    if (block in translations) {
      problems.push(`${at}: ${show(block)} is both in translations and in same`)
    }
  }

  // Each translation: a block of the English reference, in Spanish, with its original's code, links and figures.
  for (const [block, translation] of Object.entries(translations)) {
    if (!found.has(block)) {
      problems.push(`${at}: the translation of ${show(block)} matches no text of ${FILES.english} any more: update it or remove it`)
    }

    if (typeof translation !== 'string' || translation.trim() === '') {
      problems.push(`${at}: the translation of ${show(block)} is empty`)
      continue
    }

    if (translation === block) {
      problems.push(`${at}: the translation of ${show(block)} is the same text: declare it in same, with its reason`)
      continue
    }

    counts.translated += 1
    const where = `${at}: the translation of ${show(block)}`
    const leftSpans = codeSpans(block)
    const leftLinks = linksIn(block)
    const leftFigures = figuresOf(proseOf(block), 'en')
    counts.inline += leftSpans.length
    counts.links += leftLinks.length
    counts.figures += leftFigures.length

    for (const [value, count] of difference(leftSpans, codeSpans(translation))) {
      problems.push(`${where} ${count > 0 ? `lacks its inline code \`${value}\`` : `has the inline code \`${value}\`, which its original does not`}`)
    }

    for (const [value, count] of difference(leftLinks, linksIn(translation))) {
      problems.push(`${where} ${count > 0 ? `lacks its link to ${value}` : `links to ${value}, which its original does not`}`)
    }

    for (const [value, count] of difference(leftFigures, figuresOf(proseOf(translation), 'es'))) {
      problems.push(`${where} ${count > 0 ? `lacks the figure ${value}` : `has the figure ${value}, which its original does not`}`)
    }

    problems.push(...spanishProblems(translation, where, glossary))
  }

  // The title of the Spanish page.
  if (title.trim() === '') {
    problems.push(`${at}: page.title, the title of the Spanish page, is empty`)
  } else {
    problems.push(...spanishProblems(title, `${at}: page.title`, glossary))
  }

  // openapi.es.json holds the catalog's translation of every text of openapi.json, in the same place.
  const blocks = new Map([...Object.entries(translations), ...Object.keys(same).map((block) => [block, block])])
  const expected = new Map()
  mapTexts(english, ({ pointer, text }) => {
    expected.set(pointer, pointer === '/info/description' ? `${languageLine.spanish(origin)}\n\n${translate(withoutLine(text, lines.english), blocks)}` : translate(text, blocks))

    return text
  })

  for (const { pointer, text } of textsOf(spanish)) {
    if (expected.has(pointer) && expected.get(pointer) !== text) {
      problems.push(`${FILES.spanish}#${pointer}: not the catalog's translation of ${FILES.english}: run make openapi (${show(text)}, where the catalog gives ${show(expected.get(pointer))})`)
    }
  }

  return { problems, counts }
}

/** The checks of the Spanish documentation that a text of the reference passes too. */
function spanishProblems(text, where, glossary) {
  const problems = []

  if (looksEnglish(text)) {
    problems.push(`${where} still reads as English: ${show(text)}`)
  }

  for (const found of replacedTermsIn([{ type: 'paragraph', text, line: 0 }], glossary)) {
    problems.push(`${where} says "${found.variant}", which the glossary replaces with "${found.term}" (docs/es/README.md)`)
  }

  return problems
}

/** The whole check, over the files of a repository. */
export function checkReference(root) {
  const missing = Object.values(FILES).filter((path) => !existsSync(join(root, path)))

  if (missing.length > 0) {
    return { problems: missing.map((path) => `${path} is missing${path.endsWith('.json') && path.startsWith('openapi') ? ': run make openapi' : ''}`), counts: {} }
  }

  const read = (path) => JSON.parse(readFileSync(join(root, path), 'utf8'))
  const glossary = existsSync(join(root, 'docs/es/README.md')) ? glossaryOf(readFileSync(join(root, 'docs/es/README.md'), 'utf8')) : []

  return referenceProblems({ english: read(FILES.english), spanish: read(FILES.spanish), catalog: read(FILES.catalog), glossary })
}
