// docs/third-party-licenses.md, or its Spanish version docs/es/third-party-licenses.md, from the lists of licenses.mjs
// --json (scripts/licenses.sh): every production dependency of the five applications with its license, how many of
// each license each application has, and the files of third parties kept in the repository, from
// third-party-files.json (which notices.mjs checks), whose texts have their Spanish in their "es" fields. It fails,
// writing nothing, when a license is not compatible with the MIT License of this project (compatibility.mjs) or when
// a text has no Spanish.
// Usage: node third-party.mjs <directory with backend.json, web-app.json, simulator.json, cdk-app.json, functions.json> [en|es]
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { chosen, compatible, KINDS } from './compatibility.mjs'

const [directory = '/licenses', language = 'en'] = process.argv.slice(2)
const es = language === 'es'
const inventory = JSON.parse(readFileSync(new URL('./third-party-files.json', import.meta.url), 'utf8'))

// Each application: its key, its title in English and in Spanish, its lock file, the command that lists it, and its
// role in English and in Spanish.
const APPLICATIONS = [
  ['backend', 'Backend', 'Backend', 'backend/composer.lock', 'composer licenses --no-dev', 'the PHP packages of the API, the workers, the relay and the functions', 'los paquetes de PHP de la API, los workers, el relay y las funciones'],
  ['web-app', 'Web app', 'Aplicación web', 'frontend/pnpm-lock.yaml', 'pnpm licenses list --prod', 'bundled into the files the browser loads', 'empaquetados en los archivos que carga el navegador'],
  ['simulator', 'DIAN simulator', 'Simulador de la DIAN', 'simulator/package-lock.json', 'npm ls --omit=dev', 'the local stand-in for the DIAN', 'el reemplazo local de la DIAN'],
  ['cdk-app', 'CDK app', 'App de CDK', 'infra/cdk/package-lock.json', 'npm ls --omit=dev', 'the infrastructure as code of the stacks', 'la infraestructura como código de los stacks'],
  ['functions', 'Serverless service', 'Servicio serverless', 'infra/functions/package-lock.json', 'npm ls --omit=dev', 'the functions in TypeScript, their deployment and the scripts of the emulated cloud', 'las funciones en TypeScript, su despliegue y los scripts de la nube emulada'],
]
const titleOf = (application) => (es ? application[2] : application[1])
const KINDS_ES = { permissive: 'permisiva', 'weak copyleft, as a library': 'copyleft débil, como biblioteca', font: 'fuente tipográfica' }

const lists = APPLICATIONS.map(([key]) => JSON.parse(readFileSync(join(directory, `${key}.json`), 'utf8')))
const refused = lists.flatMap((list) => list.packages.flatMap((item) => item.licenses.filter((license) => !compatible(license)).map((license) => `${list.application}: ${item.name}: ${license || 'no license'}`)))

if (refused.length > 0) {
  console.error(`Licenses missing or not compatible with this project's MIT License:\n  ${refused.join('\n  ')}`)
  process.exit(1)
}

/** A text of the inventory in the page's language: its "es" field in Spanish, which must be there. */
function text(entry, field, where) {
  if (!es) {
    return entry[field]
  }

  const spanish = entry.es?.[field]

  if (typeof spanish !== 'string' || spanish === '') {
    console.error(`scripts/security/third-party-files.json: ${where} has no Spanish ${field} (its "es" field).`)
    process.exit(1)
  }

  return spanish
}

const kindOf = (expression) =>
  [...new Set((chosen(expression) ?? []).map((license) => KINDS.get(license)))]
    .map((kind) => (es ? KINDS_ES[kind] : kind))
    .join(', ')
const licenses = [...new Set(lists.flatMap((list) => list.packages.flatMap((item) => item.licenses)))].sort((a, b) => a.localeCompare(b))
const total = lists.reduce((sum, list) => sum + list.packages.length, 0)
// The packages whose installed versions declare different licenses: they count under each.
const several = lists.flatMap((list, index) => list.packages.filter((item) => item.licenses.length > 1).map((item) => `${item.name} (${titleOf(APPLICATIONS[index])}: ${item.licenses.join(', ')})`))
const count = (amount) => amount.toLocaleString(es ? 'es-CO' : 'en-US')
const lines = []

if (es) {
  lines.push(
    '# Licencias de terceros',
    '',
    '[English](../third-party-licenses.md) · **Español**',
    '',
    '<!-- Generado por make licenses (scripts/licenses.sh, scripts/security/third-party.mjs). No lo edites: make security falla cuando este archivo no es lo que dan los archivos de bloqueo. -->',
    '',
    `Las dependencias de producción de las cinco aplicaciones de este repositorio, como las instalan sus archivos de bloqueo: ${count(total)} paquetes, cada uno con la licencia que declara. Un paquete instalado en más de una versión aparece una sola vez, con la licencia de cada versión, y las versiones mismas están en los archivos de bloqueo. Las herramientas de desarrollo (linters, ejecutores de pruebas, el navegador de la prueba de punta a punta) no se distribuyen con el código y no se listan. Tampoco las imágenes de contenedor del stack local, que corren como procesos aparte. Los archivos de otros que viven en el propio repositorio vienen [después del resumen](#archivos-de-terceros-en-el-repositorio), y [NOTICE.es.txt](../../NOTICE.es.txt) recoge sus avisos.`,
    '',
    'Este proyecto está bajo la [licencia MIT](../../LICENSE). Cada licencia de abajo es compatible con ella, y `make security` lo revisa en cada ejecución contra la lista de `scripts/security/compatibility.mjs`:',
    '',
    '- Las licencias **permisivas** (MIT, ISC, BSD, Apache-2.0 y similares) piden conservar su aviso, que trae cada paquete.',
    '- Las licencias de **copyleft débil** (LGPL, EPL, MPL) cubren los archivos de su biblioteca, que aquí se usan tal como se publicaron: la biblioteca sigue bajo su licencia, y el código de este proyecto sigue siendo MIT.',
    '- Las licencias de **fuentes tipográficas** (OFL-1.1) cubren la fuente en sí, no los documentos ni las páginas que la usan.',
    '- Un paquete con licencias a elegir (`A OR B`) se usa bajo la primera alternativa compatible.',
    '',
    '## Por licencia',
    '',
    `| Licencia | Tipo | ${APPLICATIONS.map(titleOf).join(' | ')} |`,
    `| --- | --- | ${APPLICATIONS.map(() => '---:').join(' | ')} |`,
  )
} else {
  lines.push(
    '# Third-party licenses',
    '',
    '**English** · [Español](es/third-party-licenses.md)',
    '',
    '<!-- Generated by make licenses (scripts/licenses.sh, scripts/security/third-party.mjs). Do not edit: make security fails when this file is not what the lock files give. -->',
    '',
    `The production dependencies of this repository's five applications, as their lock files install them: ${count(total)} packages, each with the license it declares. A package installed in more than one version is listed once, with the license of each version, and the versions themselves are in the lock files. Development tools (linters, test runners, the end-to-end test's browser) are not distributed with the code and are not listed. Neither are the local stack's container images, which run as separate processes. The files of others that live in the repository itself come [after the summary](#files-of-third-parties-in-the-repository), and [NOTICE](../NOTICE) gives their notices.`,
    '',
    'This project is under the [MIT License](../LICENSE). Every license below is compatible with it, which `make security` checks on every run against the list in `scripts/security/compatibility.mjs`:',
    '',
    '- **Permissive** licenses (MIT, ISC, BSD, Apache-2.0 and the like) ask to keep their notice, which each package carries.',
    "- **Weak copyleft** licenses (LGPL, EPL, MPL) cover their library's files, used here as published: the library stays under its license, and this project's code stays MIT.",
    '- **Font** licenses (OFL-1.1) cover the font itself, not the documents or the pages that use it.',
    '- A package with a choice of licenses (`A OR B`) is used under the first compatible alternative.',
    '',
    '## By license',
    '',
    `| License | Kind | ${APPLICATIONS.map(titleOf).join(' | ')} |`,
    `| --- | --- | ${APPLICATIONS.map(() => '---:').join(' | ')} |`,
  )
}

for (const license of licenses) {
  const counts = lists.map((list) => list.packages.filter((item) => item.licenses.includes(license)).length)
  lines.push(`| ${license} | ${kindOf(license)} | ${counts.map((amount) => (amount === 0 ? '' : count(amount))).join(' | ')} |`)
}

lines.push(`| **${es ? 'Paquetes' : 'Packages'}** | | ${lists.map((list) => `**${count(list.packages.length)}**`).join(' | ')} |`, '')

if (several.length > 0) {
  if (es) {
    lines.push(`${several.length === 1 ? 'Un paquete está instalado' : `${several.length} paquetes están instalados`} en versiones que declaran licencias distintas, y ${several.length === 1 ? 'se cuenta' : 'se cuentan'} en cada una: ${several.join('; ')}.`, '')
  } else {
    lines.push(`${several.length === 1 ? 'One package is' : `${several.length} packages are`} installed in versions that declare different licenses, and ${several.length === 1 ? 'counts' : 'count'} under each: ${several.join('; ')}.`, '')
  }
}

const cell = (value) => value.replaceAll('|', '\\|')
const listedFiles = inventory.groups.reduce((sum, group) => sum + group.files.length, 0)

if (es) {
  lines.push(
    '## Archivos de terceros en el repositorio',
    '',
    'Estos archivos son obra de otros, y el repositorio los guarda bajo sus propios términos. [NOTICE.es.txt](../../NOTICE.es.txt) recoge sus avisos y dice que la licencia MIT de este proyecto no los cubre. `make security` revisa esta lista contra el repositorio (`scripts/security/notices.mjs`, a partir de `scripts/security/third-party-files.json`): cada archivo listado existe, el que no tenga cambios conserva su SHA-256 y su aviso, NOTICE y su traducción lo nombran, y un archivo que parece obra de un tercero, por su tipo o por un aviso de copyright o de licencia, hace fallar la revisión salvo que esté listado aquí o declarado como propio del proyecto.',
    '',
    `${inventory.groups.length} grupos, ${listedFiles} entradas:`,
    '',
    '| Archivos | Titular | Origen | Términos | Sin cambios |',
    '| --- | --- | --- | --- | --- |',
  )
} else {
  lines.push(
    '## Files of third parties in the repository',
    '',
    "These files are the work of others, kept in the repository under their own terms. [NOTICE](../NOTICE) gives their notices and says that the MIT License of this project does not cover them. `make security` checks this list against the repository (`scripts/security/notices.mjs`, from `scripts/security/third-party-files.json`): every listed file exists, an unchanged one keeps its SHA-256 and its notice, NOTICE and its translation name it, and a file that looks like the work of a third party, by its type or by a copyright or license notice in it, fails the check unless it is listed here or declared the project's own.",
    '',
    `${inventory.groups.length} groups, ${listedFiles} entries:`,
    '',
    '| Files | Owner | Origin | Terms | Unchanged |',
    '| --- | --- | --- | --- | --- |',
  )
}

for (const group of inventory.groups) {
  const files = group.files.map((entry) => (entry.glob !== undefined ? `\`${entry.glob}\`` : entry.sha256 !== undefined ? `\`${entry.path}\` (SHA-256 \`${entry.sha256.slice(0, 12)}…\`)` : `\`${entry.path}\``)).join('<br>')
  const where = `the group ${group.id}`
  lines.push(
    `| **${cell(text(group, 'name', where))}**<br>${files} | ${cell(text(group, 'owner', where))} | ${cell(text(group, 'origin', where))} [${es ? 'Fuente' : 'Source'}](${group.source}). | ${cell(text(group, 'terms', where))} | ${group.unchanged ? (es ? 'Sí' : 'Yes') : 'No'} |`,
  )
}

lines.push('', es ? '### Fragmentos en archivos de este proyecto' : '### Excerpts in files of this project', '')

for (const [index, excerpt] of inventory.excerpts.entries()) {
  const where = `the excerpt ${index + 1}`
  lines.push(`- **${text(excerpt, 'name', where)}.** ${text(excerpt, 'what', where)} ${es ? 'En' : 'In'} ${excerpt.where.map((path) => `\`${path}\``).join(', ')}. ${text(excerpt, 'terms', where)}`)
}

lines.push('', es ? '### Lo que queda fuera del repositorio' : '### Kept out of the repository', '')

for (const [index, outside] of inventory.outside.entries()) {
  const where = `the entry ${index + 1} kept outside`
  lines.push(`- **${text(outside, 'name', where)}.** ${text(outside, 'how', where)}`)
}

for (const removed of inventory.removed) {
  const where = `the removed ${removed.path}`
  lines.push(`- **\`${removed.path}\`**, ${es ? 'retirado' : 'removed'}: ${text(removed, 'what', where)} ${text(removed, 'why', where)}`)
}

lines.push('')

for (const [index, application] of APPLICATIONS.entries()) {
  const [, , , lock, command, role, roleEs] = application
  const list = lists[index]
  lines.push(
    `## ${titleOf(application)}`,
    '',
    es ? `${count(list.packages.length)} paquetes de \`${lock}\` (\`${command}\`): ${roleEs}.` : `${count(list.packages.length)} packages from \`${lock}\` (\`${command}\`): ${role}.`,
    '',
    es ? '| Paquete | Licencia |' : '| Package | License |',
    '| --- | --- |',
  )

  for (const item of list.packages) {
    lines.push(`| ${item.name} | ${item.licenses.join(', ')} |`)
  }

  lines.push('')
}

process.stdout.write(lines.join('\n'))
