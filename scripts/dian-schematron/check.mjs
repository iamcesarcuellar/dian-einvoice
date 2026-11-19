// make dian-schematron: runs the two compiled rule sets of the DIAN toolbox over one invoice, one
// credit note and one debit note, and compares every finding with the list of rules older than
// annex 1.9 that the Makefile passes in DIAN_SCHEMATRON_OUTDATED (one per line: kind|rule|text the
// message contains, or nothing|reason). A finding not in the list fails the check.
//
// Usage: node check.mjs <tools dir> <model.sef.json> <codelists.sef.json> kind=file...
import { createRequire } from 'node:module'

const [tools, modelSef, codelistsSef, ...documents] = process.argv.slice(2)
const SaxonJS = createRequire(`${tools}/package.json`)('saxon-js')

const outdated = (process.env.DIAN_SCHEMATRON_OUTDATED ?? '')
  .split('\n')
  .map((line) => line.trim())
  .filter((line) => line !== '')
  .map((line) => {
    const [kind = '', rule = '', text = '', ...reason] = line.split('|')

    return { kind: kind.trim(), rule: rule.trim(), text: text.trim(), reason: reason.join('|').trim(), seen: false }
  })

if (outdated.length === 0) {
  console.error('DIAN_SCHEMATRON_OUTDATED is empty: run this check through make dian-schematron.')
  process.exit(2)
}

/** The rule code of a message, such as FAD03 in "Fatal: [FAD03]- (R) ProfileID ...". */
function ruleOf(message) {
  return /\[([A-Za-z0-9]+)\]/.exec(message)?.[1] ?? '?'
}

/** The official compiled XSL reports every failed assert with xsl:message. */
function modelFindings(file) {
  const messages = []

  SaxonJS.transform({
    stylesheetFileName: modelSef,
    sourceFileName: file,
    destination: 'serialized',
    deliverMessage: (node) => messages.push(SaxonJS.serialize(node).replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim()),
  }, 'sync')

  return messages.map((message) => ({ set: 'model', rule: ruleOf(message), message }))
}

/** The code lists, compiled with SchXslt, report in SVRL. */
function codelistFindings(file) {
  const svrl = SaxonJS.transform({ stylesheetFileName: codelistsSef, sourceFileName: file, destination: 'serialized' }, 'sync').principalResult
  const pattern = /<svrl:(?:failed-assert|successful-report)\b[^>]*>[\s\S]*?<svrl:text>([\s\S]*?)<\/svrl:text>/g
  const findings = []

  for (const match of svrl.matchAll(pattern)) {
    const message = match[1].replace(/\s+/g, ' ').trim()
    findings.push({ set: 'code lists', rule: ruleOf(message), message })
  }

  return findings
}

let unknown = 0
let known = 0

for (const argument of documents) {
  const [kind, file] = argument.split('=')
  console.log(`\n${kind}: ${file.replace(/^\/documents\//, 'backend/storage/app/documents/')}`)

  for (const finding of [...modelFindings(file), ...codelistFindings(file)]) {
    const entry = outdated.find((candidate) => candidate.kind === kind && candidate.rule === finding.rule && (candidate.text === '' || finding.message.includes(candidate.text)))

    if (entry) {
      entry.seen = true
      known++
      console.log(`  outdated  ${finding.rule.padEnd(7)} ${entry.reason}`)
    } else {
      unknown++
      console.log(`  FAIL      ${finding.rule.padEnd(7)} ${finding.set}: ${finding.message}`)
    }
  }
}

const unseen = outdated.filter((entry) => !entry.seen)

if (unseen.length > 0) {
  console.log(`\nNot found this time (fine): ${unseen.map((entry) => `${entry.kind} ${entry.rule}${entry.text ? ` ${entry.text}` : ''}`).join(', ')}`)
}

if (unknown > 0) {
  console.log(`\ndian-schematron failed: ${unknown} finding(s) not in DIAN_SCHEMATRON_OUTDATED (${known} known outdated).`)
  process.exit(1)
}

console.log(`\ndian-schematron passed: ${known} findings, all from rules of the 2019 schematron that are older than annex 1.9; every other rule passed.`)
