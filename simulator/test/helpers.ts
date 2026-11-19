// What the tests share: the synthetic fixtures (test/fixtures), the simulator state they are valid in,
// a document edit that must find what it changes, SOAP requests, and the simulator started as a process
// of its own, on a free port and with a temporary data directory.
import assert from 'node:assert/strict'
import { spawn } from 'node:child_process'
import { once } from 'node:events'
import { readFileSync } from 'node:fs'
import { mkdtemp, rm, writeFile } from 'node:fs/promises'
import { createServer } from 'node:net'
import type { AddressInfo } from 'node:net'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { strToU8, zipSync } from 'fflate'
import { readDocument, schemaPaths } from '../src/document.ts'
import type { DocumentData, DocumentKind } from '../src/document.ts'
import { emptyState } from '../src/state.ts'
import type { DocumentRecord, State, Taxpayer } from '../src/state.ts'
import { parseXml } from '../src/xml.ts'

const ROOT = join(import.meta.dirname, '..')
const FIXTURES = join(import.meta.dirname, 'fixtures')
const FILES: Record<DocumentKind, string> = { invoice: 'invoice.xml', credit_note: 'credit-note.xml', debit_note: 'debit-note.xml' }
const PREFIXES: Record<DocumentKind, string> = { invoice: 'fv', credit_note: 'nc', debit_note: 'nd' }

export const KINDS: readonly DocumentKind[] = ['invoice', 'credit_note', 'debit_note']
export const SCHEMAS = schemaPaths(process.env.SIMULATOR_XSD_DIR ?? join(ROOT, 'xsd'))
/** The issuer of the fixtures, as the backend registered it in the simulator. */
export const TAXPAYER = JSON.parse(readFileSync(join(FIXTURES, 'taxpayer.json'), 'utf8')) as Taxpayer
export const TOKEN = 'test-token'

/** A signed fixture, as the backend issued it. */
export function fixture(kind: DocumentKind): string {
  return readFileSync(join(FIXTURES, FILES[kind]), 'utf8')
}

export function read(xml: string): DocumentData {
  const parsed = parseXml(xml)
  assert.ok(parsed, 'the XML is not well formed')

  return readDocument(parsed)
}

/** The XML with the first match of the pattern replaced; fails when there is nothing to change. */
export function change(xml: string, pattern: RegExp, replace: (match: string, ...groups: string[]) => string): string {
  assert.ok(pattern.test(xml), `the fixture has nothing to change for ${String(pattern)}`)

  return xml.replace(pattern, replace)
}

/** An invoice of the fixture issuer that the simulator accepted. */
export function acceptedInvoice(cufe: string | undefined, number: string | undefined, issueDate: string | undefined): DocumentRecord {
  assert.ok(cufe !== undefined && number !== undefined, 'an invoice needs its CUFE and number')

  return {
    cufe: cufe.toLowerCase(),
    kind: 'invoice',
    references: null,
    nit: TAXPAYER.nit,
    number,
    issue_date: issueDate ?? null,
    issuer_name: TAXPAYER.legal_name,
    issuer_check_digit: TAXPAYER.check_digit,
    status: 'accepted',
    findings: [],
    response_number: 1,
    responded_at: '2026-06-01T15:00:00.000Z',
    xml_file_name: null,
    received_at: '2026-06-01T15:00:00.000Z',
    submissions: 1,
  }
}

/** The state the fixtures pass in: their issuer registered, and the invoices the notes correct accepted. */
export function fixtureState(): State {
  const state = emptyState()
  state.taxpayers[TAXPAYER.nit] = structuredClone(TAXPAYER)

  for (const kind of ['credit_note', 'debit_note'] as const) {
    const reference = read(fixture(kind)).reference
    const invoice = acceptedInvoice(reference?.cufe, reference?.id, reference?.issueDate)
    state.documents[invoice.cufe] = invoice
  }

  return state
}

/** A SOAP 1.2 request of the DIAN web service. */
export function envelope(operation: 'SendBillSync' | 'GetStatus', fields: Record<string, string>): string {
  const parameters = Object.entries(fields).map(([name, value]) => `<wcf:${name}>${value}</wcf:${name}>`).join('')

  return `<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:wcf="http://wcf.dian.colombia"><soap:Header/><soap:Body><wcf:${operation}>${parameters}</wcf:${operation}></soap:Body></soap:Envelope>`
}

/** SendBillSync of a document of the fixture issuer, zipped under the names of annex 1.9 (6.5.7, 6.5.8). */
export function sendBillSync(xml: string, kind: DocumentKind): string {
  const name = `${TAXPAYER.nit.padStart(10, '0')}0002600000001`
  const zip = zipSync({ [`${PREFIXES[kind]}${name}.xml`]: strToU8(xml) })

  return envelope('SendBillSync', { fileName: `z${name}.zip`, contentFile: Buffer.from(zip).toString('base64') })
}

export interface Answer {
  /** The HTTP status, or how the request ended without one. */
  status: number | 'timeout' | 'reset'
  text: string
  ms: number
}

/** IsValid and the ErrorMessage strings of a DianResponse. */
export function result(answer: Answer): { valid: boolean, messages: string[] } {
  return {
    valid: answer.text.includes('<b:IsValid>true</b:IsValid>'),
    messages: [...answer.text.matchAll(/<c:string>([^<]*)<\/c:string>/g)].map((match) => match[1] ?? ''),
  }
}

export interface Simulator {
  /** A POST; the answer, or how it ended without one. */
  post: (path: string, body: string, headers?: Record<string, string>, timeoutMs?: number) => Promise<Answer>
  /** A call of the admin API, which must answer 200. */
  admin: (method: string, path: string, body?: unknown) => Promise<unknown>
  stop: () => Promise<void>
}

function freePort(): Promise<number> {
  return new Promise((resolve, reject) => {
    const probe = createServer()
    probe.once('error', reject)
    probe.listen(0, '127.0.0.1', () => {
      const { port } = probe.address() as AddressInfo
      probe.close(() => resolve(port))
    })
  })
}

/** src/server.ts in a process of its own, starting from the given state. */
export async function startSimulator(state: State): Promise<Simulator> {
  const data = await mkdtemp(join(tmpdir(), 'simulator-test-'))
  await writeFile(join(data, 'state.json'), JSON.stringify(state))
  const port = await freePort()
  const env: NodeJS.ProcessEnv = { ...process.env, PORT: String(port), SIMULATOR_DATA: data, SIMULATOR_ADMIN_TOKEN: TOKEN }
  // The server is not a test file of the runner.
  delete env.NODE_TEST_CONTEXT

  const server = spawn(process.execPath, [join(ROOT, 'src', 'server.ts')], { env, stdio: ['ignore', 'pipe', 'inherit'] })
  let output = ''

  await new Promise<void>((resolve, reject) => {
    server.once('exit', (code) => reject(new Error(`The simulator exited with ${code}: ${output}`)))
    server.stdout.on('data', (chunk: Buffer) => {
      output += chunk.toString()

      if (output.includes('listening on port')) {
        resolve()
      }
    })
  })

  const url = `http://127.0.0.1:${port}`

  return {
    async post(path, body, headers = { 'content-type': 'application/soap+xml; charset=utf-8' }, timeoutMs = 10_000) {
      const started = performance.now()

      try {
        const response = await fetch(`${url}${path}`, { method: 'POST', headers, body, signal: AbortSignal.timeout(timeoutMs) })

        return { status: response.status, text: await response.text(), ms: performance.now() - started }
      } catch (error) {
        const timeout = error instanceof DOMException && error.name === 'TimeoutError'

        return { status: timeout ? 'timeout' : 'reset', text: error instanceof Error ? error.message : 'failed', ms: performance.now() - started }
      }
    },
    async admin(method, path, body) {
      const response = await fetch(`${url}${path}`, {
        method,
        headers: { 'x-simulator-token': TOKEN, 'content-type': 'application/json' },
        body: body === undefined ? null : JSON.stringify(body),
      })
      const answer: unknown = await response.json()
      assert.equal(response.status, 200, JSON.stringify(answer))

      return answer
    },
    async stop() {
      if (server.exitCode === null && server.signalCode === null) {
        const exited = once(server, 'exit')
        server.kill('SIGTERM')
        await exited
      }

      await rm(data, { recursive: true, force: true })
    },
  }
}
