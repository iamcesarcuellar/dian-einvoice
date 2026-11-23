// The runbooks of the alarms (docs/runbooks) against the alarms of both stages: every alarm
// names a runbook that exists, the index lists every alarm with its runbook and the service objectives it defends,
// every page of the folder is listed (no orphan), every runbook of an alarm has its six parts, and every saved query a
// runbook names is one the stack observability saves. The commands they cite are checked where they live: the make
// targets and the AWS CLI in infra/functions/test/runbooks.test.mjs, the commands of the application in the backend.
import assert from 'node:assert/strict'
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, it } from 'node:test'
import { RUNBOOKS, SAVED_QUERIES, SERVICE_OBJECTIVES } from '../lib/observability.ts'
import { stageOf, template } from './support.ts'

const docs = process.env.DOCS ?? join(import.meta.dirname, '../../../docs')
const folder = join(docs, 'runbooks')
const index = readFileSync(join(folder, 'README.md'), 'utf8')
const pages = readdirSync(folder).filter((file) => file.endsWith('.md') && file !== 'README.md').sort()

/** Every alarm of a stage, the composite one included, by its short name, with the runbook its description names. */
function alarmsOf(stage: 'local' | 'prod'): Map<string, string> {
  const observability = template(stageOf(stage).observability)
  const alarms = [
    ...Object.values(observability.findResources('AWS::CloudWatch::Alarm')),
    ...Object.values(observability.findResources('AWS::CloudWatch::CompositeAlarm')),
  ] as { Properties: { AlarmName: string, AlarmDescription: string } }[]

  return new Map(alarms.map((alarm) => [alarm.Properties.AlarmName.replace(`dian-einvoice-${stage}-`, ''), /Runbook: (docs\/runbooks\/[a-z-]+\.md)$/.exec(alarm.Properties.AlarmDescription)?.[1] ?? '']))
}

const alarms = new Map([...alarmsOf('local'), ...alarmsOf('prod')])

/** The rows of the table of alarms of the index: alarm, runbook, objectives. */
function indexRows(): Map<string, { runbook: string, objectives: string }> {
  const section = index.slice(index.indexOf('## Alarms'), index.indexOf('## Procedures'))

  return new Map([...section.matchAll(/^\| `([a-z-]+)`(?: \(prod\))? \| \[([a-z-]+\.md)\]\([a-z-]+\.md\) \| ([^|]+) \|$/gm)].map((row) => [row[1] ?? '', { runbook: `docs/runbooks/${row[2] ?? ''}`, objectives: (row[3] ?? '').trim() }]))
}

describe('the runbooks of the alarms', () => {
  it('exist for every alarm of both stages, the one its description names', () => {
    assert.ok(alarms.size >= 17, `${alarms.size} alarms`)

    for (const [alarm, runbook] of alarms) {
      assert.ok((Object.values(RUNBOOKS) as string[]).includes(runbook), `${alarm} names ${runbook}, a runbook of RUNBOOKS`)
      assert.ok(existsSync(join(docs, runbook.replace(/^docs\//, ''))), `${alarm}: ${runbook} exists`)
    }

    for (const runbook of Object.values(RUNBOOKS)) {
      assert.ok(existsSync(join(docs, runbook.replace(/^docs\//, ''))), `${runbook} exists`)
    }
  })

  it('are listed in the index, each alarm with its runbook and the service objectives it defends', () => {
    const rows = indexRows()
    assert.deepEqual([...rows.keys()].sort(), [...alarms.keys()].sort())

    for (const [alarm, runbook] of alarms) {
      const row = rows.get(alarm)
      assert.equal(row?.runbook, runbook, `the index gives ${alarm} its runbook`)
      const defended = SERVICE_OBJECTIVES.filter((objective) => (objective.alarms as readonly string[]).includes(alarm)).map((objective) => objective.name)
      assert.equal(defended.length === 0 ? row?.objectives.startsWith('none') : row?.objectives === defended.join(', '), true, `${alarm} defends ${defended.join(', ') || 'no objective'} (the index says ${row?.objectives})`)
    }
  })

  it('leave no page of the folder out of the index', () => {
    const linked = new Set([...index.matchAll(/\]\(([a-z-]+\.md)(?:#[a-z-]+)?\)/g)].map((link) => link[1]))
    assert.deepEqual(pages.filter((page) => !linked.has(page)), [], 'every runbook is reachable from docs/runbooks/README.md')
    const procedures = pages.filter((page) => !(Object.values(RUNBOOKS) as string[]).includes(`docs/runbooks/${page}`))
    assert.deepEqual(procedures, ['customer-report.md', 'drill-report.md', 'rotate-secrets.md'], 'the pages that no alarm names are the procedures and the report of the drill')
  })

  it('say, for each alarm, what it means, how to confirm it, its causes, the response, the recovery and what to review', () => {
    for (const runbook of new Set(Object.values(RUNBOOKS))) {
      const text = readFileSync(join(docs, runbook.replace(/^docs\//, '')), 'utf8')
      const headings = [...text.matchAll(/^## (.+)$/gm)].map((heading) => heading[1])
      assert.deepEqual(headings, ['What it means and who it affects', 'Confirm it', 'Probable causes', 'Respond', 'Verify the recovery', 'Afterwards'], runbook)
    }
  })

  it('name only saved queries the stack observability saves', () => {
    const saved = new Set(SAVED_QUERIES.map((query) => query.name))

    for (const page of pages) {
      for (const [, name] of readFileSync(join(folder, page), 'utf8').matchAll(/saved query `dian-einvoice\/(?:prod|local)\/([a-z-]+)`/g)) {
        assert.ok(saved.has(name ?? ''), `${page} names the saved query ${name}`)
      }
    }
  })
})
