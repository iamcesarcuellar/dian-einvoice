// The commands the runbooks (docs/runbooks) and the deployment guide (docs/deployment.md) cite exist, in English and in
// their Spanish translations (docs/es/runbooks, docs/es/deployment.md), without a network:
// every make target is a target of the Makefile, every step of scripts/deploy.sh one it runs, and every command of the
// AWS CLI an operation (or a waiter) of the AWS SDK of that service, which is generated from the same models as the CLI.
// Every step of a runbook that runs in the emulator has its command for a real account. And make cloud-drill responds
// with the commands of the runbook only. The commands of the application (php artisan) are checked by the backend
// (tests/Integration/Operations/RunbooksTest.php), the alarms and their runbooks by the CDK app.
import assert from 'node:assert/strict'
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { join } from 'node:path'
import { describe, it } from 'node:test'

// The repository as the container functions mounts it (docs, the Makefile and the scripts, read-only), or the checkout.
const root = process.env.REPOSITORY ?? (existsSync('/workspace/docs') ? '/workspace' : join(import.meta.dirname, '../../..'))
const docs = join(root, 'docs')
const read = (path) => readFileSync(path, 'utf8')
const pagesOf = (folder) => (existsSync(folder) ? readdirSync(folder).filter((file) => file.endsWith('.md')).map((file) => join(folder, file)) : [])
const english = [...pagesOf(join(docs, 'runbooks')), join(docs, 'deployment.md')]
const spanish = [...pagesOf(join(docs, 'es', 'runbooks')), ...(existsSync(join(docs, 'es', 'deployment.md')) ? [join(docs, 'es', 'deployment.md')] : [])]
const guides = [...english, ...spanish]
const makefile = read(join(root, 'Makefile'))
const deployScript = read(join(root, 'scripts/deploy.sh'))
const drillScript = read(join(root, 'scripts/cloud-drill.sh'))
const require = createRequire(import.meta.url)

/** The inline code spans of a page of Markdown: what an operator copies. */
function spans(text) {
  return [...text.matchAll(/`([^`\n]+)`/g)].map((match) => match[1])
}

const cited = guides.flatMap((guide) => spans(read(guide)).map((code) => ({ guide: guide.replace(`${docs}/`, 'docs/'), code })))

/** The targets of the Makefile. */
const targets = new Set([...makefile.matchAll(/^([a-z][a-z0-9-]*):/gm)].map((match) => match[1]))

/** The steps scripts/deploy.sh runs: the labels of its case of steps. */
const steps = new Set([...deployScript.slice(deployScript.indexOf('case "$step" in')).matchAll(/^ {2}([a-z|\s-]+)\)$/gm)].flatMap((match) => match[1].split('|').map((step) => step.trim())))

/** The AWS CLI names a service by its own name; the AWS SDK for JavaScript names its client package. */
const CLIENTS = {
  acm: 'acm',
  apigatewayv2: 'apigatewayv2',
  cloudformation: 'cloudformation',
  cloudfront: 'cloudfront',
  cloudwatch: 'cloudwatch',
  ecr: 'ecr',
  iam: 'iam',
  lambda: 'lambda',
  logs: 'cloudwatch-logs',
  rds: 'rds',
  scheduler: 'scheduler',
  secretsmanager: 'secrets-manager',
  'service-quotas': 'service-quotas',
  sesv2: 'sesv2',
  sns: 'sns',
  sqs: 'sqs',
  ssm: 'ssm',
  sts: 'sts',
}

/** Whether the AWS CLI has `aws <service> <operation>`, or `aws <service> wait <waiter>`. */
function awsCommandExists(service, operation, waiter) {
  const client = CLIENTS[service]

  if (client === undefined) {
    return false
  }

  const exports = Object.keys(require(`@aws-sdk/client-${client}`)).map((name) => name.toLowerCase())
  const pascal = (name) => name.replaceAll('-', '').toLowerCase()

  return operation === 'wait' ? exports.includes(`waituntil${pascal(waiter ?? '')}`) : exports.includes(`${pascal(operation)}command`)
}

describe('the commands the runbooks and the deployment guide cite', () => {
  it('are make targets of the Makefile', () => {
    const missing = cited.filter(({ code }) => /^make [a-z]/.test(code)).filter(({ code }) => !targets.has(code.split(/\s+/)[1])).map(({ guide, code }) => `${guide}: ${code}`)
    assert.ok(cited.some(({ code }) => code.startsWith('make cloud-redrive')), 'the runbooks cite make targets')
    assert.deepEqual(missing, [])
  })

  it('are steps of scripts/deploy.sh, for a stage it knows', () => {
    const uses = cited.flatMap(({ guide, code }) => [...code.matchAll(/sh scripts\/deploy\.sh (\S+) ([a-z-]+)/g)].map((match) => ({ guide, stage: match[1], step: match[2] })))
    assert.ok(uses.length > 0)
    assert.deepEqual(uses.filter(({ stage, step }) => !['local', 'prod'].includes(stage) || !steps.has(step)).map(({ guide, stage, step }) => `${guide}: ${stage} ${step}`), [])
    assert.ok(['diff', 'stacks', 'image', 'migrate', 'functions', 'edge', 'observability', 'panel', 'smoke', 'versions', 'rollback-image', 'rollback-panel'].every((step) => steps.has(step)), [...steps].join(' '))
  })

  it('are operations of the AWS CLI, as the AWS SDK of each service has them', () => {
    const commands = cited.flatMap(({ guide, code }) => [...code.matchAll(/(?:^|[\s($"])aws ([a-z0-9-]+) ([a-z0-9-]+)(?: ([a-z0-9-]+))?/g)].map((match) => ({ guide, service: match[1], operation: match[2], waiter: match[3] })))
    assert.ok(commands.length >= 40, `${commands.length} commands of the AWS CLI`)
    const missing = commands.filter(({ service, operation, waiter }) => !awsCommandExists(service, operation, waiter)).map(({ guide, service, operation, waiter }) => `${guide}: aws ${service} ${operation}${operation === 'wait' ? ` ${waiter}` : ''}`)
    assert.deepEqual([...new Set(missing)], [])
  })

  it('have their Spanish translation, page for page', () => {
    assert.deepEqual(
      spanish.map((path) => path.replace(`${docs}/es/`, '')).sort(),
      english.map((path) => path.replace(`${docs}/`, '')).sort(),
    )
  })

  it('give each step of a runbook that runs in the emulator its command for a real account', () => {
    for (const guide of guides.filter((path) => path.includes('/runbooks/'))) {
      const lines = read(guide).split('\n')

      lines.forEach((line, index) => {
        if (/^\s+- (Emulator|Emulador):/.test(line)) {
          assert.match(lines[index + 1] ?? '', /^\s+- AWS:/, `${guide.replace(`${docs}/`, 'docs/')}:${index + 1} has its command for a real account`)
        }
      })
    }
  })
})

describe('make cloud-drill', () => {
  it('responds with the commands of its runbooks only, as an operator runs them', () => {
    const runbooks = ['dead-letters.md', 'dian-errors.md'].map((page) => read(join(docs, 'runbooks', page))).join('\n')
    // A command as the runbook writes it: placeholders and quotes aside.
    const shape = (command) => command.replace(/"\$[a-z_]+"|<[^>]+>/g, 'X').replaceAll('"', '').replace(/\s+/g, ' ').trim()
    const written = new Set(spans(runbooks).filter((code) => code.startsWith('make ')).map((code) => shape(code)))
    const run = [...drillScript.matchAll(/^\s*(?:if )?runbook (.+?)(?:; then| \|\| status=1)?$/gm)].map((match) => shape(`make ${match[1]}`))

    assert.ok(run.length >= 6, `${run.length} commands of the runbook`)
    assert.deepEqual(run.filter((command) => !written.has(command)), [], `the runbooks write ${[...written].join(' | ')}`)
    assert.ok(run.includes('make cloud-redrive QUEUE=chain'), 'the response moves the dead letters back with the native redrive of SQS')
  })
})
