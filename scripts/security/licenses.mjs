// License check of the production dependencies (make security): a dependency whose license is only GPL or
// AGPL (strong copyleft, incompatible with releasing this project under a permissive license) fails, and so
// does one without a license. LGPL, MPL, EPL and the permissive licenses pass; "A OR B" passes when one
// alternative does. Reads, on stdin:
//   composer   the JSON of composer licenses --no-dev --format=json
//   pnpm       the JSON of pnpm licenses list --prod --json
//   npm        the paths of npm ls --omit=dev --all --parseable, one per line (it reads each package.json)
// Usage: node licenses.mjs <composer|pnpm|npm> <name of the application>
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

const [format, application = format] = process.argv.slice(2)
const input = readFileSync(0, 'utf8')

/** @type {Array<{ name: string, license: string }>} */
let packages = []

if (format === 'composer') {
  const data = JSON.parse(input)
  packages = Object.entries(data.dependencies ?? {}).map(([name, info]) => ({ name, license: (info.license ?? []).join(' OR ') }))
} else if (format === 'pnpm') {
  const data = JSON.parse(input)
  packages = Object.entries(data).flatMap(([license, list]) => list.map((item) => ({ name: item.name, license })))
} else if (format === 'npm') {
  const paths = input.split('\n').map((line) => line.trim()).filter(Boolean).slice(1)
  packages = paths.map((path) => {
    const manifest = JSON.parse(readFileSync(join(path, 'package.json'), 'utf8'))
    const license = typeof manifest.license === 'string' ? manifest.license : (manifest.licenses ?? []).map((item) => item.type).join(' OR ')

    return { name: manifest.name, license }
  })
} else {
  console.error(`Unknown format ${format}: composer, pnpm or npm.`)
  process.exit(2)
}

/** GPL or AGPL in any version, "-only", "-or-later" or "+": not LGPL. */
const strongCopyleft = (identifier) => /^A?GPL(v?\d|-|\+|$)/i.test(identifier.trim())

/** Whether the license expression leaves a choice that is not strong copyleft. */
function allowed(expression) {
  const alternatives = expression.replace(/[()]/g, ' ').split(/\s+OR\s+/i).map((part) => part.trim()).filter(Boolean)

  return alternatives.length > 0 && alternatives.some((alternative) => !alternative.split(/\s+AND\s+/i).some(strongCopyleft))
}

const refused = packages.filter((item) => item.license.trim() === '' || !allowed(item.license))
const counts = Object.entries(packages.reduce((all, item) => ({ ...all, [item.license]: (all[item.license] ?? 0) + 1 }), {}))
  .sort(([, a], [, b]) => b - a)
  .map(([license, count]) => `${license} ${count}`)

console.log(`${application}: ${packages.length} production dependencies. ${counts.join(', ')}.`)

if (refused.length > 0) {
  console.error(`${application}: licenses incompatible with a permissive license, or missing:`)

  for (const item of refused) {
    console.error(`  ${item.name}: ${item.license || 'no license'}`)
  }

  process.exit(1)
}
