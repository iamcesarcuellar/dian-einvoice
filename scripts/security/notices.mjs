// make security (scripts/licenses.sh): the files of third parties in the repository are exactly the ones
// third-party-files.json lists. Each listed file exists; an unchanged one still has its SHA-256 and keeps the notice of
// its group; NOTICE names every listed path; a file the inventory marks as removed stays out. Then every other file of
// the repository is scanned for the work of others, by its type (schemas, style sheets, fonts, images, documents,
// archives, XML) or by a copyright or license notice in its text: one that is neither listed nor declared the
// project's own fails the check. It writes nothing.
//
//   git ls-files --cached --others --exclude-standard | node notices.mjs <root of the repository>
import { createHash } from 'node:crypto'
import { existsSync, readFileSync, statSync } from 'node:fs'
import { extname, join } from 'node:path'

const root = process.argv[2] ?? '/repo'
const inventory = JSON.parse(readFileSync(new URL('./third-party-files.json', import.meta.url), 'utf8'))

// Types that a project writes rarely and copies often: the work of others until the inventory says otherwise.
const TYPES = new Set(['.xsd', '.xsl', '.xslt', '.sch', '.gc', '.xml', '.dtd', '.ttf', '.otf', '.woff', '.woff2', '.eot', '.png', '.jpg', '.jpeg', '.gif', '.webp', '.svg', '.ico', '.pdf', '.xls', '.xlsx', '.doc', '.docx', '.jar', '.zip', '.gz', '.tgz', '.wasm', '.pem', '.crt', '.cer', '.der', '.p12', '.pfx'])
// The marks of a notice of someone else's rights in a text.
const MARKS = [/copyright\s*(\(c\)|©|\d{4})/i, /all rights reserved/i, /SPDX-License-Identifier/, /permission is hereby granted/i, /licensed under the /i]

const glob = (pattern) =>
  new RegExp(
    `^${pattern
      .split(/(\*\*|\*)/)
      .map((part) => (part === '**' ? '.*' : part === '*' ? '[^/]*' : part.replace(/[.+?^${}()|[\]\\]/g, '\\$&')))
      .join('')}$`,
  )

const files = readFileSync(0, 'utf8')
  .split('\n')
  .map((line) => line.trim())
  .filter((path) => path !== '' && existsSync(join(root, path)) && statSync(join(root, path)).isFile())
const problems = []
const notice = existsSync(join(root, 'NOTICE')) ? readFileSync(join(root, 'NOTICE'), 'utf8') : null

if (notice === null) {
  problems.push('NOTICE is missing at the root of the repository.')
}

const listed = new Set()
const globs = []
let hashed = 0
let noticed = 0

for (const group of inventory.groups) {
  for (const entry of group.files) {
    if (entry.glob !== undefined) {
      const pattern = glob(entry.glob)
      const matched = files.filter((path) => pattern.test(path))
      globs.push(pattern)
      matched.forEach((path) => listed.add(path))

      if (matched.length === 0) {
        problems.push(`${group.id}: no file matches ${entry.glob}`)
      }

      if (notice !== null && !notice.includes(entry.glob)) {
        problems.push(`${group.id}: NOTICE does not name ${entry.glob}`)
      }

      continue
    }

    listed.add(entry.path)

    if (!files.includes(entry.path)) {
      problems.push(`${group.id}: ${entry.path} is listed but is not in the repository`)
      continue
    }

    const bytes = readFileSync(join(root, entry.path))

    if (entry.sha256 !== undefined) {
      hashed += 1

      if (createHash('sha256').update(bytes).digest('hex') !== entry.sha256) {
        problems.push(`${group.id}: ${entry.path} is listed as unchanged but its SHA-256 is not the one listed`)
      }
    }

    if (group.notice) {
      noticed += 1

      if (!bytes.toString('utf8').toLowerCase().includes(group.notice.toLowerCase())) {
        problems.push(`${group.id}: ${entry.path} no longer keeps its notice "${group.notice}"`)
      }
    }

    if (notice !== null && !notice.includes(entry.path)) {
      problems.push(`${group.id}: NOTICE does not name ${entry.path}`)
    }
  }
}

for (const removed of inventory.removed) {
  if (files.includes(removed.path)) {
    problems.push(`${removed.path} is listed as removed but is in the repository`)
  }
}

const declared = [...inventory.excerpts.flatMap((excerpt) => excerpt.where), ...inventory.own.flatMap((own) => own.paths)]

for (const pattern of declared) {
  if (!files.some((path) => glob(pattern).test(path))) {
    problems.push(`third-party-files.json declares ${pattern}, which matches no file`)
  }
}

const known = declared.map(glob)
let scanned = 0

for (const path of files) {
  if (listed.has(path) || globs.some((pattern) => pattern.test(path)) || known.some((pattern) => pattern.test(path))) {
    continue
  }

  scanned += 1

  if (TYPES.has(extname(path).toLowerCase())) {
    problems.push(`${path}: a file of a type that is usually the work of others, not in scripts/security/third-party-files.json`)
    continue
  }

  const bytes = readFileSync(join(root, path))

  if (bytes.length > 8 * 1024 * 1024 || bytes.subarray(0, 8000).includes(0)) {
    continue
  }

  const mark = MARKS.find((pattern) => pattern.test(bytes.toString('utf8')))

  if (mark !== undefined) {
    problems.push(`${path}: a copyright or license notice (${mark.source}), and the file is not in scripts/security/third-party-files.json`)
  }
}

if (problems.length > 0) {
  console.error(`Third-party files: ${problems.length} problems.\n${problems.map((problem) => `  ${problem}`).join('\n')}`)
  process.exit(1)
}

console.log(
  `Third-party files: ${listed.size} files in ${inventory.groups.length} groups, as scripts/security/third-party-files.json lists them (${hashed} unchanged with their SHA-256, ${noticed} with their notice) and NOTICE names them; ${scanned} other files scanned, none of them the work of others.`,
)
