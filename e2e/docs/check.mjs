// make docs-check (part of make lint): the README and the documentation hold true. Every make target and every file of
// the repository that README.md, README.es.md, docs/README.md and the reference documents name exist; every relative link of every
// Markdown file of the repository resolves, its anchor included; every image they show exists and keeps to its size (a
// screenshot of docs/assets 400 KB, a GIF 5 MB); and every Mermaid diagram can be drawn, by Mermaid itself in the
// browser of the container. It writes nothing.
//
//   node docs/check.mjs <root of the repository>
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, extname, join, relative, resolve } from 'node:path'
import { chromium } from 'playwright'

const root = resolve(process.argv[2] ?? '/repo')
const LIMITS = { '.png': 400 * 1024, '.gif': 5 * 1024 * 1024 }
// Generated, installed or local working files: never documentation of the repository.
const SKIPPED = new Set(['.git', '.claude', 'node_modules', 'vendor', 'dist', 'cdk.out', 'storage', 'test-results', '.package', '.serverless', 'CLAUDE.md'])
// The directories and files at the root a path of the docs may start with.
const ROOTS = /^(backend|frontend|simulator|infra|scripts|docs|e2e|\.github)\//
const ROOT_FILES = new Set(['Makefile', 'compose.yaml', 'LICENSE', 'README.md', 'README.es.md', 'SECURITY.md', 'CONTRIBUTING.md', 'openapi.json', '.gitleaks.toml', '.gitleaksignore', '.editorconfig'])
// Files the docs name that make creates, never in git: they exist once the target that writes them has run.
const GENERATED = new Map([['backend/.env', 'make env']])
// The documents whose make targets and files are checked, and the folder their short paths start from: the
// architecture and the DIAN conformance write the backend's paths from backend/ (app/..., tests/..., config/...).
const NAMED = new Map([
  ['README.md', null],
  ['README.es.md', null],
  ['docs/README.md', null],
  ['docs/architecture.md', 'backend'],
  ['docs/api-guide.md', null],
  ['docs/dian-conformance.md', 'backend'],
  ['docs/cost.md', null],
])
const BACKEND_PATHS = /^(app|bootstrap|config|database|resources|routes|tests)\//

const problems = []
const counts = { files: 0, links: 0, anchors: 0, images: 0, targets: 0, paths: 0, diagrams: 0 }

function markdownFiles(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    if (SKIPPED.has(entry.name)) {
      return []
    }

    const path = join(directory, entry.name)

    return entry.isDirectory() ? markdownFiles(path) : entry.name.endsWith('.md') ? [path] : []
  })
}

/** The text without its fenced code blocks, which hold no links. */
function prose(text) {
  return text.replace(/^(```|~~~)[^\n]*\n[\s\S]*?^\1[ \t]*$/gm, '')
}

/** The anchors GitHub gives the headings of a Markdown file, and the ids of its HTML anchors. */
function anchorsOf(file) {
  const seen = new Map()
  const anchors = new Set()

  for (const [, heading] of prose(readFileSync(file, 'utf8')).matchAll(/^#{1,6}[ \t]+(.+?)[ \t#]*$/gm)) {
    const text = heading
      .replace(/!?\[([^\]]*)\]\([^)]*\)/g, '$1')
      .replace(/<[^>]+>/g, '')
      .replace(/[`*]/g, '')
    const slug = text
      .trim()
      .toLowerCase()
      .replace(/[^\p{L}\p{M}\p{N}\p{Pc} -]/gu, '')
      .replace(/ /g, '-')
    const count = seen.get(slug) ?? 0
    seen.set(slug, count + 1)
    anchors.add(count === 0 ? slug : `${slug}-${count}`)
  }

  for (const [, id] of readFileSync(file, 'utf8').matchAll(/<a\s+(?:id|name)="([^"]+)"/g)) {
    anchors.add(id)
  }

  return anchors
}

const anchorCache = new Map()
const anchors = (file) => anchorCache.get(file) ?? anchorCache.set(file, anchorsOf(file)).get(file)

function checkLink(file, target, image) {
  const at = relative(root, file)

  if (/^[a-z][a-z0-9+.-]*:/i.test(target) || target.startsWith('//')) {
    return
  }

  counts.links += 1
  const [path, fragment] = target.split('#', 2)
  const destination = path === '' ? file : resolve(dirname(file), decodeURIComponent(path))

  if (!existsSync(destination)) {
    problems.push(`${at}: the link ${target} goes to nothing`)

    return
  }

  if (!destination.startsWith(root)) {
    problems.push(`${at}: the link ${target} leaves the repository`)
  }

  if (fragment !== undefined && fragment !== '' && destination.endsWith('.md')) {
    counts.anchors += 1

    if (!anchors(destination).has(fragment)) {
      problems.push(`${at}: the link ${target} names an anchor ${relative(root, destination)} does not have`)
    }
  }

  const limit = LIMITS[extname(destination).toLowerCase()]

  if (image) {
    counts.images += 1

    if (limit !== undefined && relative(root, destination).startsWith('docs/assets/') && statSync(destination).size > limit) {
      problems.push(`${at}: ${relative(root, destination)} is ${Math.round(statSync(destination).size / 1024)} KB, over ${limit / 1024} KB`)
    }
  }
}

/** The make targets of the Makefile: every rule, and every name of its TARGETS list. */
function targetsOf() {
  const makefile = readFileSync(join(root, 'Makefile'), 'utf8')
  const targets = new Set([...makefile.matchAll(/^([a-z][a-z0-9-]*):(?!=)/gm)].map(([, name]) => name))

  for (const name of makefile.match(/^TARGETS := (.*)$/m)?.[1].split(/\s+/) ?? []) {
    targets.add(name)
  }

  return targets
}

const targets = targetsOf()
const diagrams = []

for (const file of markdownFiles(root).sort()) {
  counts.files += 1
  const text = readFileSync(file, 'utf8')
  const body = prose(text)

  for (const [, image, target] of body.matchAll(/(!?)\[(?:[^\]\\]|\\.)*\]\(\s*<?([^)\s>]+)>?(?:\s+"[^"]*")?\s*\)/g)) {
    checkLink(file, target, image === '!')
  }

  for (const [, target] of body.matchAll(/^\s*\[[^\]]+\]:\s*<?([^\s>]+)>?/gm)) {
    checkLink(file, target, false)
  }

  for (const [, tag, target] of body.matchAll(/<(img|a|source)\s[^>]*?(?:src|href|srcset)="([^"]+)"/g)) {
    checkLink(file, target, tag !== 'a')
  }

  for (const [, code] of text.matchAll(/^```mermaid[ \t]*\n([\s\S]*?)^```[ \t]*$/gm)) {
    diagrams.push({ file: relative(root, file), code })
  }

  // The make targets and the files the README, the index of the documentation and the reference documents name, in code.
  if (NAMED.has(relative(root, file))) {
    const base = NAMED.get(relative(root, file))
    const code = [...text.matchAll(/^```[^\n]*\n([\s\S]*?)^```[ \t]*$/gm)].map(([, block]) => block).concat([...body.matchAll(/`([^`\n]+)`/g)].map(([, span]) => span))

    for (const snippet of code) {
      for (const [, target] of snippet.matchAll(/(?:^|[\s;&|(])make\s+([a-z][a-z0-9-]*)/gm)) {
        counts.targets += 1

        if (!targets.has(target)) {
          problems.push(`${relative(root, file)}: make ${target} is not a target of the Makefile`)
        }
      }

      for (const token of snippet.split(/[\s"'=(),;]+/)) {
        // A test method after its file (DocumentKeyTest.php::test_...) names the file.
        const path = token.replace(/::.*$/, '').replace(/[.:]+$/, '')
        const full = ROOTS.test(path) || ROOT_FILES.has(path) ? path : base !== null && BACKEND_PATHS.test(path) ? `${base}/${path}` : null

        if (full === null || /[<>*{}$|]|:\/\//.test(path)) {
          continue
        }

        counts.paths += 1

        if (!GENERATED.has(full) && !existsSync(join(root, full))) {
          problems.push(`${relative(root, file)}: the file ${full} does not exist`)
        }
      }
    }
  }
}

// Every diagram drawn by Mermaid, the version this package pins, in a page of its own.
if (diagrams.length > 0) {
  const require = createRequire(import.meta.url)
  const browser = await chromium.launch()

  try {
    const page = await browser.newPage()
    await page.setContent('<!doctype html><html><body></body></html>')
    await page.addScriptTag({ path: require.resolve('mermaid/dist/mermaid.min.js') })

    for (const [index, { file, code }] of diagrams.entries()) {
      const error = await page.evaluate(
        async ({ code, id }) => {
          const mermaid = globalThis.mermaid
          mermaid.initialize({ startOnLoad: false, securityLevel: 'strict' })

          try {
            await mermaid.parse(code)
            const { svg } = await mermaid.render(id, code)

            return svg.includes('<svg') ? null : 'no drawing'
          } catch (thrown) {
            return thrown instanceof Error ? thrown.message : String(thrown)
          }
        },
        { code, id: `diagram-${index}` },
      )

      counts.diagrams += 1

      if (error !== null) {
        problems.push(`${file}: diagram ${index + 1} cannot be drawn: ${error.split('\n')[0]}`)
      }
    }
  } finally {
    await browser.close()
  }
}

console.log(
  `docs: ${counts.files} Markdown files, ${counts.links} relative links (${counts.anchors} with an anchor), ${counts.images} images, ${counts.targets} make targets and ${counts.paths} files named by README.md, README.es.md, docs/README.md and the ${NAMED.size - 3} reference documents, ${counts.diagrams} Mermaid diagrams drawn.`,
)

if (problems.length > 0) {
  console.error(problems.map((problem) => `  ${problem}`).join('\n'))
  process.exit(1)
}
