// make docs-examples: the examples of README.md, README.es.md and docs/api-guide.md run against the demo (make demo, in
// English or with LOCALE=es in Spanish: the examples hold for both), as a reader
// would run them, and the check fails when one stops working. Inside the e2e container (compose profile e2e), with the
// repository read-only at /repo:
//
//   - a fenced sh block after <!-- example --> runs in one bash session per document, in order, in a new empty
//     directory, with localhost:8600 passed on to the API (as on the host);
//   - a fenced text block after <!-- output --> is what the example before it must print, line for line; a <word> in
//     it stands for any text without spaces (an id, a number, a date);
//   - in README.md and README.es.md, dei_... (the key make demo printed) becomes a key this check creates through a session and
//     revokes at the end;
//   - the blocks after <!-- example: node --> and <!-- example: php --> verify a webhook signature: this check runs
//     the Node.js one here, and writes the PHP one with its cases for the api container (make docs-examples runs it
//     next), against the Standard Webhooks reference vector and a real webhook the demo sends to a receiver this
//     check runs, through a subscription it creates and deletes.
//
//   node docs/examples.mjs /repo
import { spawn } from 'node:child_process'
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs'
import { createServer } from 'node:http'
import { connect, createServer as createTcpServer } from 'node:net'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { pathToFileURL } from 'node:url'

const root = process.argv[2] ?? '/repo'
const API_HOST = process.env.API_HOST ?? 'api'
const API = 'http://localhost:8600/api/v1'
const ORIGIN = 'http://localhost:8601'
const RECEIVER_PORT = 8645
const RECEIVER_URL = `http://${process.env.RECEIVER_HOST ?? 'e2e'}:${RECEIVER_PORT}/webhooks`
const OUT = '/e2e/test-results/docs-examples'
const MARK = '@@docs-example-done@@'
const TIMEOUT_MS = 240_000
const DOCUMENTS = ['README.md', 'README.es.md', 'docs/api-guide.md']
// The documents whose examples use the key make demo printed.
const WITH_DEMO_KEY = new Set(['README.md', 'README.es.md'])

// The Standard Webhooks reference example (www.standardwebhooks.com), the one tests/Unit/Developers/WebhookSignatureTest.php uses.
const VECTOR = {
  secret: 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw',
  headers: { 'webhook-id': 'msg_p5jXN8AQM9LWM0D4loKWxJek', 'webhook-timestamp': '1614265330', 'webhook-signature': 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=' },
  body: '{"test": 2432232314}',
}

const problems = []

/** localhost:8600 inside this container is the API of the compose network, as it is on the host. */
createTcpServer((client) => {
  const upstream = connect(8000, API_HOST)
  client.pipe(upstream).pipe(client)
  client.on('error', () => upstream.destroy())
  upstream.on('error', () => client.destroy())
}).listen(8600, '127.0.0.1')

/** The examples and expected outputs of a Markdown file, in order. */
function blocksOf(path) {
  const text = readFileSync(join(root, path), 'utf8')
  const blocks = []

  for (const [, kind, language, code] of text.matchAll(/^<!-- (example(?:: [a-z]+)?|output) -->\s*\n```([a-z]*)[^\n]*\n([\s\S]*?)^```[ \t]*$/gm)) {
    blocks.push({ kind, language, code })
  }

  return blocks
}

/** A session of the demo owner, as the web app holds one: cookies, the CSRF token and the company. */
async function signIn() {
  const cookies = new Map()
  const send = async (path, init = {}) => {
    const response = await fetch(`http://localhost:8600${path}`, {
      ...init,
      redirect: 'manual',
      headers: {
        Accept: 'application/json',
        Origin: ORIGIN,
        Cookie: [...cookies].map(([name, value]) => `${name}=${value}`).join('; '),
        'X-XSRF-TOKEN': decodeURIComponent(cookies.get('XSRF-TOKEN') ?? ''),
        ...(init.body === undefined ? {} : { 'Content-Type': 'application/json' }),
        ...init.headers,
      },
    })

    for (const cookie of response.headers.getSetCookie()) {
      const [pair] = cookie.split(';')
      const at = pair.indexOf('=')
      cookies.set(pair.slice(0, at), pair.slice(at + 1))
    }

    return response
  }

  await send('/sanctum/csrf-cookie')
  const login = await send('/api/v1/auth/login', { method: 'POST', body: JSON.stringify({ email: 'demo@example.com', password: 'password' }) })

  if (!login.ok) {
    throw new Error(`The demo owner could not sign in (HTTP ${login.status}): run make demo first.`)
  }

  const company = (await login.json()).data.companies.find((candidate) => candidate.name === 'Demo Comercial SAS')?.id

  if (company === undefined) {
    throw new Error('The demo has no Demo Comercial SAS: run make demo first.')
  }

  return (path, init = {}) => send(`/api/v1${path}`, { ...init, headers: { 'X-Company-Id': company, ...init.headers } })
}

/** Runs the examples of one document in one bash session and compares what each prints with its expected output. */
function run(path, blocks, replacements) {
  const examples = []
  let pending = null

  for (const block of blocks) {
    if (block.kind === 'example' && block.language === 'sh') {
      pending = { code: block.code, expected: null }
      examples.push(pending)
    } else if (block.kind === 'output' && pending !== null) {
      pending.expected = block.code
      pending = null
    }
  }

  if (examples.length === 0) {
    problems.push(`${path}: no <!-- example --> block`)

    return Promise.resolve()
  }

  let script = ['set -eu', `cd "${mkdtempSync(join(tmpdir(), 'docs-example-'))}"`, ...examples.flatMap((example, index) => [example.code, `printf '\\n%s\\n' '${MARK}${index}'`])].join('\n')

  for (const [from, to] of replacements) {
    script = script.replaceAll(from, to)
  }

  return new Promise((resolve) => {
    const child = spawn('bash', ['-c', script], { stdio: ['ignore', 'pipe', 'pipe'] })
    let stdout = ''
    let stderr = ''
    const timer = setTimeout(() => child.kill('SIGKILL'), TIMEOUT_MS)
    child.stdout.on('data', (chunk) => (stdout += chunk))
    child.stderr.on('data', (chunk) => (stderr += chunk))
    child.on('close', (code) => {
      clearTimeout(timer)
      const outputs = stdout.replaceAll('\r', '').split(new RegExp(`\\n?${MARK}\\d+\\n`))
      const finished = (stdout.match(new RegExp(MARK, 'g')) ?? []).length

      if (code !== 0 || finished < examples.length) {
        problems.push(`${path}: example ${finished + 1} of ${examples.length} stopped (exit ${code ?? 'killed after the timeout'}).\n${indent(`${outputs[finished] ?? ''}\n${stderr}`.trim())}`)
      }

      for (const [index, example] of examples.entries()) {
        if (example.expected === null || index >= finished) {
          continue
        }

        const actual = lines(outputs[index])
        const expected = lines(example.expected)
        const matches = actual.length === expected.length && expected.every((line, at) => pattern(line).test(actual[at]))

        if (!matches) {
          problems.push(`${path}: example ${index + 1} printed something else.\n  expected:\n${indent(expected.join('\n'), 4)}\n  printed:\n${indent(actual.join('\n'), 4)}`)
        }
      }

      console.log(`${path}: ${Math.min(finished, examples.length)} of ${examples.length} examples ran, ${examples.filter((example) => example.expected !== null).length} with their output checked.`)
      resolve()
    })
  })
}

const lines = (text) =>
  text
    .split('\n')
    .map((line) => line.trimEnd())
    .join('\n')
    .trim()
    .split('\n')
const pattern = (line) => new RegExp(`^${line.split(/(<[^<>\s]+>)/).map((part) => (/^<[^<>\s]+>$/.test(part) ? '\\S+' : part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))).join('')}$`)
const indent = (text, spaces = 2) => text.replace(/^/gm, ' '.repeat(spaces))

/** A webhook the demo really sends: a subscription of its own to a receiver here, a test event, then the subscription goes. */
async function realWebhook(session) {
  let resolveCapture
  const captured = new Promise((resolve) => (resolveCapture = resolve))
  const server = createServer((incoming, response) => {
    const chunks = []
    incoming.on('data', (chunk) => chunks.push(chunk))
    incoming.on('end', () => {
      response.writeHead(204).end()
      resolveCapture({ headers: { 'webhook-id': incoming.headers['webhook-id'], 'webhook-timestamp': incoming.headers['webhook-timestamp'], 'webhook-signature': incoming.headers['webhook-signature'] }, body: Buffer.concat(chunks).toString('utf8') })
    })
  }).listen(RECEIVER_PORT, '0.0.0.0')

  try {
    const created = await session('/webhooks', { method: 'POST', body: JSON.stringify({ url: RECEIVER_URL, events: ['invoice.accepted'], description: 'make docs-examples (deleted at the end)' }) })

    if (created.status !== 201) {
      throw new Error(`The subscription for the signature examples was refused (HTTP ${created.status}): ${await created.text()}`)
    }

    const subscription = (await created.json()).data

    try {
      const test = await session(`/webhooks/${subscription.id}/test`, { method: 'POST' })

      if (test.status !== 201) {
        throw new Error(`The test event was refused (HTTP ${test.status}).`)
      }

      const webhook = await Promise.race([captured, new Promise((_, reject) => setTimeout(() => reject(new Error('No webhook arrived in 60 s: are the workers of the stack up?')), 60_000))])

      return { secret: subscription.secret, ...webhook }
    } finally {
      await session(`/webhooks/${subscription.id}`, { method: 'DELETE' })
    }
  } finally {
    server.close()
  }
}

/** The cases every signature example must get right. */
function signatureCases(webhook) {
  const other = `whsec_${Buffer.alloc(32, 7).toString('base64')}`

  return [
    { name: 'the Standard Webhooks reference example', ...VECTOR, tolerance: 2_000_000_000, valid: true },
    { name: 'the reference example with its body changed', ...VECTOR, body: VECTOR.body.replace('2432232314', '2432232315'), tolerance: 2_000_000_000, valid: false },
    { name: 'the reference example, older than the default tolerance of 5 minutes', ...VECTOR, tolerance: 300, valid: false },
    { name: 'a webhook the demo sent', ...webhook, tolerance: 300, valid: true },
    { name: 'that webhook with another secret', ...webhook, secret: other, tolerance: 300, valid: false },
    { name: 'that webhook without its signature', ...webhook, headers: { ...webhook.headers, 'webhook-signature': undefined }, tolerance: 300, valid: false },
  ]
}

try {
  const session = await signIn()
  const created = await session('/api-keys', { method: 'POST', body: JSON.stringify({ name: 'make docs-examples (revoked at the end)', level: 'read_write' }) })

  if (created.status !== 201) {
    throw new Error(`No API key for the examples (HTTP ${created.status}).`)
  }

  const key = (await created.json()).data

  try {
    for (const path of DOCUMENTS) {
      await run(path, blocksOf(path), WITH_DEMO_KEY.has(path) ? [['dei_...', key.key]] : [])
    }
  } finally {
    await session(`/api-keys/${key.id}/revoke`, { method: 'POST' })
  }

  const blocks = blocksOf('docs/api-guide.md')
  const node = blocks.find((block) => block.kind === 'example: node')
  const php = blocks.find((block) => block.kind === 'example: php')
  const webhook = await realWebhook(session)
  const cases = signatureCases(webhook)
  mkdirSync(OUT, { recursive: true })

  if (node === undefined || php === undefined) {
    problems.push('docs/api-guide.md: the signature examples (<!-- example: node --> and <!-- example: php -->) are missing')
  } else {
    writeFileSync(join(OUT, 'verify.mjs'), node.code)
    const { verifyWebhook } = await import(pathToFileURL(join(OUT, 'verify.mjs')).href)
    const wrong = cases.filter((item) => verifyWebhook(item.secret, item.headers, item.body, item.tolerance) !== item.valid)
    wrong.forEach((item) => problems.push(`docs/api-guide.md: the Node.js example gets ${item.name} wrong`))
    console.log(`docs/api-guide.md: the Node.js signature example got ${cases.length - wrong.length} of ${cases.length} cases right, a real webhook of the demo among them.`)

    writeFileSync(join(OUT, 'cases.json'), JSON.stringify(cases.map((item) => ({ ...item, headers: Object.fromEntries(Object.entries(item.headers).filter(([, value]) => value !== undefined)) }))))
    writeFileSync(
      join(OUT, 'verify.php'),
      `${php.code.trimEnd()}\n\n// The cases of e2e/docs/examples.mjs, written next to this file.\n$cases = json_decode((string) file_get_contents(__DIR__.'/cases.json'), true, 512, JSON_THROW_ON_ERROR);\n$wrong = array_filter($cases, fn (array $case): bool => verify_webhook($case['secret'], $case['headers'], $case['body'], $case['tolerance']) !== $case['valid']);\nforeach ($wrong as $case) {\n    fwrite(STDERR, "docs/api-guide.md: the PHP example gets {$case['name']} wrong\\n");\n}\necho 'docs/api-guide.md: the PHP signature example got '.(count($cases) - count($wrong)).' of '.count($cases)." cases right, a real webhook of the demo among them.\\n";\nexit($wrong === [] ? 0 : 1);\n`,
    )
  }
} catch (error) {
  problems.push(error instanceof Error ? error.message : String(error))
}

if (problems.length > 0) {
  console.error(problems.map((problem) => `  ${problem}`).join('\n'))
  process.exit(1)
}

process.exit(0)
