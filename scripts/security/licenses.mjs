// The production dependencies of one application with their versions and licenses (make security, make licenses,
// through scripts/licenses.sh), checked against the licenses compatible with the MIT License of this project
// (compatibility.mjs): an expression passes when one of its OR alternatives has every AND part in that list, so a
// dependency under GPL or AGPL, under a license not in the list, or without a license fails. Reads, on stdin:
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

/** @type {Array<{ name: string, version: string, license: string }>} */
let packages = []

if (format === 'composer') {
  const data = JSON.parse(input)
  packages = Object.entries(data.dependencies ?? {}).map(([name, info]) => ({ name, version: String(info.version ?? '').replace(/^v/, ''), license: (info.license ?? []).join(' OR ') }))
} else if (format === 'pnpm') {
  const data = JSON.parse(input)
  packages = Object.entries(data).flatMap(([license, list]) => list.map((item) => ({ name: item.name, version: (item.versions ?? [item.version]).join(', '), license })))
} else if (format === 'npm') {
  const paths = input.split('\n').map((line) => line.trim()).filter(Boolean).slice(1)
  packages = paths.map((path) => {
    const manifest = JSON.parse(readFileSync(join(path, 'package.json'), 'utf8'))
    const license = typeof manifest.license === 'string' ? manifest.license : (manifest.licenses ?? []).map((item) => item.type).join(' OR ')

    return { name: manifest.name, version: manifest.version ?? '', license }
  })
} else {
  console.error(`Unknown format ${format}: composer, pnpm or npm.`)
  process.exit(2)
}

// npm lists a package once per place it is installed; the same name and version is one dependency.
packages = [...new Map(packages.map((item) => [`${item.name}@${item.version}`, item])).values()].sort((a, b) => a.name.localeCompare(b.name) || a.version.localeCompare(b.version))

const refused = packages.filter((item) => !compatible(item.license))

if (output === '--json') {
  process.stdout.write(`${JSON.stringify({ application, packages }, null, 2)}\n`)
} else {
  const counts = Object.entries(packages.reduce((all, item) => ({ ...all, [item.license]: (all[item.license] ?? 0) + 1 }), {}))
    .sort(([, a], [, b]) => b - a)
    .map(([license, count]) => `${license} ${count}`)

  console.log(`${application}: ${packages.length} production dependencies. ${counts.join(', ')}.`)
}

if (refused.length > 0) {
  console.error(`${application}: licenses not compatible with the MIT License of this project, or missing:`)

  for (const item of refused) {
    console.error(`  ${item.name} ${item.version}: ${item.license || 'no license'}`)
  }

  process.exit(1)
}
