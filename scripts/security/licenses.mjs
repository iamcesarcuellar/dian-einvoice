// The production dependencies of one application with their licenses (make security, make licenses, through
// scripts/licenses.sh), checked against the licenses compatible with the MIT License of this project
// (compatibility.mjs): an expression passes when one of its OR alternatives has every AND part in that list, so a
// dependency under GPL or AGPL, under a license not in the list, or without a license fails. A package is listed once,
// with every license its installed versions declare; the versions themselves live in the lock files. Reads, on stdin:
//   composer   the JSON of composer licenses --no-dev --format=json
//   pnpm       the JSON of pnpm licenses list --prod --json
//   npm        the paths of npm ls --omit=dev --all --parseable, one per line (it reads each package.json)
// It prints a summary, or with --json the list itself, for docs/third-party-licenses.md.
// Usage: node licenses.mjs <composer|pnpm|npm> <name of the application> [--json]
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { compatible } from './compatibility.mjs'

const [format, application = format, output = 'summary'] = process.argv.slice(2)
const input = readFileSync(0, 'utf8')

/** @type {Array<{ name: string, license: string }>} Each installed copy of a package, with the license it declares. */
let installed = []

if (format === 'composer') {
  const data = JSON.parse(input)
  installed = Object.entries(data.dependencies ?? {}).map(([name, info]) => ({ name, license: (info.license ?? []).join(' OR ') }))
} else if (format === 'pnpm') {
  const data = JSON.parse(input)
  installed = Object.entries(data).flatMap(([license, list]) => list.map((item) => ({ name: item.name, license })))
} else if (format === 'npm') {
  const paths = input.split('\n').map((line) => line.trim()).filter(Boolean).slice(1)
  installed = paths.map((path) => {
    const manifest = JSON.parse(readFileSync(join(path, 'package.json'), 'utf8'))
    const license = typeof manifest.license === 'string' ? manifest.license : (manifest.licenses ?? []).map((item) => item.type).join(' OR ')

    return { name: manifest.name, license }
  })
} else {
  console.error(`Unknown format ${format}: composer, pnpm or npm.`)
  process.exit(2)
}

// npm lists a package once per place it is installed, and a package may be installed in several versions: each name
// is one dependency, with every license its copies declare.
const licensesOf = installed.reduce((all, item) => all.set(item.name, (all.get(item.name) ?? new Set()).add(item.license)), new Map())
/** @type {Array<{ name: string, licenses: string[] }>} */
const packages = [...licensesOf].map(([name, licenses]) => ({ name, licenses: [...licenses].sort((a, b) => a.localeCompare(b)) })).sort((a, b) => a.name.localeCompare(b.name))

const refused = packages.flatMap((item) => item.licenses.filter((license) => !compatible(license)).map((license) => ({ name: item.name, license })))

if (output === '--json') {
  process.stdout.write(`${JSON.stringify({ application, packages }, null, 2)}\n`)
} else {
  const counts = Object.entries(packages.flatMap((item) => item.licenses).reduce((all, license) => ({ ...all, [license]: (all[license] ?? 0) + 1 }), {}))
    .sort(([, a], [, b]) => b - a)
    .map(([license, count]) => `${license} ${count}`)

  console.log(`${application}: ${packages.length} production dependencies. ${counts.join(', ')}.`)
}

if (refused.length > 0) {
  console.error(`${application}: licenses missing or not compatible with this project's MIT License:`)

  for (const item of refused) {
    console.error(`  ${item.name}: ${item.license || 'no license'}`)
  }

  process.exit(1)
}
