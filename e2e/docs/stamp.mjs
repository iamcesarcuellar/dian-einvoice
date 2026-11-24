// make docs-stamp: seals each Spanish translation that follows its original again, writing the original's SHA-256
// into its stamp line (translations.mjs). A translation is sealed only when it passes every check of its pair against
// the original as it is now: its language lines, its code, inline code, links, figures and structure, no paragraph
// left in English and no term the glossary replaces. The others are listed with what keeps them back, and the target
// fails. The documents a generator writes carry no stamp.
//
//   node docs/stamp.mjs <root of the repository>
import { execFileSync } from 'node:child_process'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { anchorsOf, Exceptions, glossaryOf, loadExceptions, pairProblems, pairsOf, parse, sha256, stampOf, translationOf, withStamp } from './translations.mjs'

const root = resolve(process.argv[2] ?? '/repo')
const files = execFileSync('git', ['-C', root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], { encoding: 'utf8' })
  .split('\0')
  .filter((path) => path !== '' && existsSync(join(root, path)))
const read = (path) => readFileSync(join(root, path), 'utf8')
const exists = (path) => existsSync(join(root, path))
const anchorsFor = (path) => (exists(path) && path.endsWith('.md') ? anchorsOf(parse(read(path))) : [])
const pairOf = (path) => {
  const pair = translationOf(path)

  return pair !== null && exists(pair) ? pair : null
}
const glossary = exists('docs/es/README.md') ? glossaryOf(read('docs/es/README.md')) : []
const exceptions = new Exceptions(loadExceptions(root))
const { pairs, problems: missing } = pairsOf(files)
const sealed = []
const held = []

for (const { original, translation, generated } of pairs.filter((pair) => !pair.generated)) {
  const originalText = read(original)
  const translationText = read(translation)
  const hash = sha256(originalText)

  if (generated || stampOf(translationText) === hash) {
    continue
  }

  const problems = pairProblems({ original, translation, originalText, translationText, pairOf, exists, anchorsFor, exceptions, glossary })

  if (problems.length > 0) {
    held.push(`${translation}, not sealed:\n${problems.map((problem) => `    ${problem}`).join('\n')}`)
    continue
  }

  writeFileSync(join(root, translation), withStamp(translationText, original, hash))
  sealed.push(translation)
}

console.log(sealed.length === 0 ? 'docs-stamp: no translation to seal.' : `docs-stamp: ${sealed.length} sealed with their original's SHA-256:\n${sealed.map((path) => `  ${path}`).join('\n')}`)

if (held.length > 0 || missing.length > 0) {
  console.error([...missing.map((problem) => `  ${problem}`), ...held.map((problem) => `  ${problem}`)].join('\n'))
  process.exit(1)
}
