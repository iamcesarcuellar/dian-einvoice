// Local simulator of the DIAN web service for electronic invoicing, a development tool of
// dian-einvoice. It is not the DIAN: nothing it accepts is registered anywhere.
//
//   POST /WcfDianCustomerServices.svc   SOAP 1.2: SendBillSync and GetStatus (annex 1.9, section 7)
//   GET  /health
//   /admin/...                          taxpayers, failure modes, activity (X-Simulator-Token)
import { createServer } from 'node:http'
import type { IncomingMessage, ServerResponse } from 'node:http'
import type { Socket } from 'node:net'
import { join } from 'node:path'
import { unzipSync } from 'fflate'
import { applicationResponse } from './application-response.ts'
import { readInvoice } from './invoice.ts'
import { ALL, MODE_NAMES, currentMode, normalMode, takeMode } from './modes.ts'
import { RULES, finding, formatFinding, rule } from './rules.ts'
import type { Finding } from './rules.ts'
import { faultEnvelope, parseRequest, resultEnvelope } from './soap.ts'
import type { DianResult, SoapRequest } from './soap.ts'
import { Store } from './state.ts'
import type { DocumentRecord, Mode, ModeName, Range, RequestLog, Taxpayer } from './state.ts'
import { validateInvoice } from './validate.ts'
import { parseXml } from './xml.ts'

const PORT = Number(process.env.PORT ?? 8602)
const TOKEN = process.env.SIMULATOR_ADMIN_TOKEN ?? ''
const SCHEMA = join(import.meta.dirname, '..', 'xsd', 'maindoc', 'UBL-Invoice-2.1.xsd')
const MAX_BODY = 20 * 1024 * 1024
const REQUEST_LOG = 500
/** How long a request in no_response mode is held open before the socket is dropped. */
const HOLD_MS = 5 * 60 * 1000

const store = new Store(process.env.SIMULATOR_DATA ?? '/data')
const state = store.state
const sockets = new Set<Socket>()

interface Answer {
  result: DianResult
  outcome: string
  document: string | null
  rules: string[]
}

class HttpError extends Error {
  readonly status: number

  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

// ---------------------------------------------------------------------------------------------
// HTTP plumbing

function readBody(request: IncomingMessage): Promise<string> {
  return new Promise((resolve, reject) => {
    const chunks: Buffer[] = []
    let size = 0

    request.on('data', (chunk: Buffer) => {
      size += chunk.length

      if (size > MAX_BODY) {
        reject(new HttpError(413, 'The request is too large.'))
        request.destroy()

        return
      }

      chunks.push(chunk)
    })
    request.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')))
    request.on('error', reject)
  })
}

function json(response: ServerResponse, status: number, body: unknown): void {
  response.writeHead(status, { 'content-type': 'application/json; charset=utf-8' })
  response.end(JSON.stringify(body))
}

function soapResponse(response: ServerResponse, status: number, envelope: string): void {
  response.writeHead(status, { 'content-type': 'application/soap+xml; charset=utf-8' })
  response.end(envelope)
}

function sleep(milliseconds: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, milliseconds))
}

function log(entry: Omit<RequestLog, 'at'>): void {
  state.requests.unshift({ at: new Date().toISOString(), ...entry })

  if (state.requests.length > REQUEST_LOG) {
    state.requests.length = REQUEST_LOG
  }

  store.save()
  console.log(`${entry.operation} nit=${entry.nit ?? '-'} document=${entry.document ?? '-'} result=${entry.result}${entry.mode ? ` mode=${entry.mode}` : ''} ${entry.duration_ms} ms`)
}

// ---------------------------------------------------------------------------------------------
// SOAP: SendBillSync and GetStatus

/** The NIT in a zip name of annex 1.9, 6.5.8: z + NIT in ten digits + ppp + aa + dddddddd. */
function nitFromFileName(fileName: string | undefined): string | null {
  const match = /^z(\d{10})\d{3}\d{2}[0-9a-f]{8}\.zip$/i.exec(fileName ?? '')

  return match ? match[1].replace(/^0+/, '') : null
}

function resultOf(record: DocumentRecord): DianResult {
  const accepted = record.status === 'accepted'

  return {
    isValid: accepted,
    statusCode: accepted ? '00' : '99',
    statusDescription: accepted ? 'Procesado Correctamente.' : 'Validación contiene errores en campos mandatorios.',
    statusMessage: accepted ? `La Factura electrónica ${record.number}, ha sido autorizada.` : 'Documento con errores en campos mandatorios.',
    errorMessages: record.findings.map(formatFinding),
    applicationResponse: applicationResponse(record),
    documentKey: record.cufe,
    fileName: record.xml_file_name?.replace(/\.xml$/i, '') ?? null,
  }
}

/** A rejection before the document could be read: no ApplicationResponse and nothing stored. */
function unreadable(messages: Finding[], documentKey: string | null = null): DianResult {
  return {
    isValid: false,
    statusCode: '99',
    statusDescription: 'Validación contiene errores en campos mandatorios.',
    statusMessage: 'Documento con errores en campos mandatorios.',
    errorMessages: messages.map(formatFinding),
    applicationResponse: null,
    documentKey,
    fileName: null,
  }
}

async function sendBillSync(request: SoapRequest, mode: Mode | null): Promise<Answer> {
  const zip = Buffer.from(request.contentFile ?? '', 'base64')

  if (zip.length === 0) {
    return { result: unreadable([{ code: null, kind: 'rejection', message: 'El archivo ZIP está vacío.' }]), outcome: 'rejected', document: null, rules: [] }
  }

  let entries: Record<string, Uint8Array>

  try {
    entries = unzipSync(new Uint8Array(zip))
  } catch {
    return { result: unreadable([{ code: null, kind: 'rejection', message: 'El archivo ZIP está corrupto.' }]), outcome: 'rejected', document: null, rules: [] }
  }

  const names = Object.keys(entries).filter((name) => name.toLowerCase().endsWith('.xml'))

  if (names.length !== 1) {
    return { result: unreadable([{ code: null, kind: 'rejection', message: names.length === 0 ? 'El archivo ZIP no contiene un documento XML.' : 'El archivo ZIP contiene más de un documento XML.' }]), outcome: 'rejected', document: null, rules: [] }
  }

  const xmlFileName = names[0]
  const xml = new TextDecoder('utf-8').decode(entries[xmlFileName])
  const parsed = parseXml(xml)

  if (!parsed) {
    return { result: unreadable([finding('ZB01', 'the XML is not well formed')]), outcome: 'rejected', document: null, rules: ['ZB01'] }
  }

  const invoice = readInvoice(parsed)
  const key = invoice.cufe?.toLowerCase()

  if (!key || !invoice.number || !invoice.supplierNit || !invoice.issueDate || !invoice.customerId || invoice.ublVersion !== 'UBL 2.1') {
    return { result: unreadable([finding('ZB01', 'CUFE, number, issue date, issuer and customer are required, and UBLVersionID must be UBL 2.1')]), outcome: 'rejected', document: invoice.number ?? null, rules: ['ZB01'] }
  }

  // The same document again: the earlier result, without validating it again.
  const previous = state.documents[key]

  if (previous?.status === 'accepted') {
    previous.submissions += 1
    store.save()

    return { result: resultOf(previous), outcome: 'duplicate', document: previous.number, rules: previous.findings.flatMap((item) => (item.code ? [item.code] : [])) }
  }

  // Another document with a number already accepted for this issuer.
  const sameNumber = Object.values(state.documents).find((record) => record.status === 'accepted' && record.nit === invoice.supplierNit && record.number === invoice.number)

  if (sameNumber) {
    return { result: unreadable([finding('90')], key), outcome: 'rejected', document: invoice.number, rules: ['90'] }
  }

  const findings = await validateInvoice(xml, invoice, state, SCHEMA)

  // A copy sent at the same time finished first: this one is a repetition of it.
  const meanwhile = state.documents[key]

  if (meanwhile !== undefined && meanwhile !== previous && meanwhile.status === 'accepted') {
    meanwhile.submissions += 1
    store.save()

    return { result: resultOf(meanwhile), outcome: 'duplicate', document: meanwhile.number, rules: meanwhile.findings.flatMap((item) => (item.code ? [item.code] : [])) }
  }

  if (mode?.mode === 'reject' && mode.rule) {
    findings.push(finding(mode.rule, undefined, true))
  }

  const accepted = !findings.some((item) => item.kind === 'rejection')
  const now = new Date().toISOString()
  const record: DocumentRecord = {
    cufe: key,
    nit: invoice.supplierNit,
    number: invoice.number,
    issue_date: invoice.issueDate ?? null,
    issuer_name: invoice.supplierName ?? null,
    issuer_check_digit: invoice.supplierCheckDigit ?? null,
    status: accepted ? 'accepted' : 'rejected',
    findings,
    response_number: ++state.response_sequence,
    responded_at: now,
    xml_file_name: xmlFileName,
    received_at: now,
    submissions: (previous?.submissions ?? 0) + 1,
  }

  state.documents[key] = record
  store.save()

  return { result: resultOf(record), outcome: record.status, document: record.number, rules: findings.flatMap((item) => (item.code ? [item.code] : [])) }
}

function getStatus(request: SoapRequest): Answer {
  const trackId = (request.trackId ?? '').toLowerCase()
  const record = state.documents[trackId]

  if (!record) {
    return {
      result: {
        isValid: false,
        statusCode: '90',
        statusDescription: 'TrackId no encontrado.',
        statusMessage: null,
        errorMessages: [],
        applicationResponse: null,
        documentKey: request.trackId ?? null,
        fileName: null,
      },
      outcome: 'not_found',
      document: null,
      rules: [],
    }
  }

  return { result: resultOf(record), outcome: `found ${record.status}`, document: record.number, rules: record.findings.flatMap((item) => (item.code ? [item.code] : [])) }
}

async function soap(request: IncomingMessage, response: ServerResponse): Promise<void> {
  const started = performance.now()
  const elapsed = () => Math.round(performance.now() - started)
  const envelope = parseRequest(await readBody(request))

  if (!envelope || (envelope.operation !== 'SendBillSync' && envelope.operation !== 'GetStatus')) {
    soapResponse(response, 400, faultEnvelope('Sender', envelope ? `The local DIAN simulator does not implement ${envelope.operation}; it offers SendBillSync and GetStatus.` : 'The request is not a SOAP 1.2 envelope.'))
    log({ operation: envelope?.operation ?? 'unknown', nit: null, document: null, result: 'invalid_request', status_code: null, duration_ms: elapsed(), mode: null, rules: [] })

    return
  }

  const operation = envelope.operation
  const nit = operation === 'SendBillSync' ? nitFromFileName(envelope.fileName) : (state.documents[(envelope.trackId ?? '').toLowerCase()]?.nit ?? null)
  const mode = takeMode(state, nit, operation)

  if (mode !== null) {
    store.save()
  }

  const fail = (how: 'error_500' | 'reset', label: string): void => {
    if (how === 'error_500') {
      soapResponse(response, 500, faultEnvelope('Receiver', `Simulated internal error of the local DIAN simulator (${label}).`))
    } else {
      request.socket.destroy()
    }

    log({ operation, nit, document: envelope.trackId ?? envelope.fileName ?? null, result: how, status_code: null, duration_ms: elapsed(), mode: label, rules: [] })
  }

  if (mode?.mode === 'error_500') {
    fail('error_500', 'error_500')

    return
  }

  if (mode?.mode === 'intermittent' && Math.random() * 100 < (mode.failure_rate ?? 0)) {
    fail(Math.random() < 0.5 ? 'error_500' : 'reset', 'intermittent')

    return
  }

  const answer = operation === 'SendBillSync' ? await sendBillSync(envelope, mode) : getStatus(envelope)

  if (mode?.mode === 'slow') {
    await sleep(mode.delay_ms ?? 0)
  }

  const entry = { operation, nit: nit ?? null, document: answer.document, status_code: answer.result.statusCode, mode: mode?.mode ?? null, rules: answer.rules }

  if (mode?.mode === 'no_response') {
    // Processed, but the answer never leaves: as if it were lost on the way back.
    const timer = setTimeout(() => request.socket.destroy(), HOLD_MS)
    request.socket.once('close', () => clearTimeout(timer))
    log({ ...entry, result: `${answer.outcome} (answer withheld)`, duration_ms: elapsed() })

    return
  }

  soapResponse(response, 200, resultEnvelope(operation, answer.result))
  log({ ...entry, result: answer.outcome, duration_ms: elapsed() })
}

// ---------------------------------------------------------------------------------------------
// Admin API

function asRanges(value: unknown): Range[] {
  if (!Array.isArray(value)) {
    throw new HttpError(422, 'ranges must be a list.')
  }

  return value.map((item: Record<string, unknown>) => {
    if (typeof item?.resolution_number !== 'string' || typeof item.technical_key !== 'string' || !Number.isInteger(item.from) || !Number.isInteger(item.to)) {
      throw new HttpError(422, 'Each range needs resolution_number, from, to and technical_key.')
    }

    return {
      resolution_number: item.resolution_number,
      prefix: typeof item.prefix === 'string' ? item.prefix : '',
      from: item.from as number,
      to: item.to as number,
      valid_from: typeof item.valid_from === 'string' ? item.valid_from : null,
      valid_to: typeof item.valid_to === 'string' ? item.valid_to : null,
      technical_key: item.technical_key,
    }
  })
}

function asTaxpayer(nit: string, body: Record<string, unknown>): Taxpayer {
  const software = body.software as Record<string, unknown> | null | undefined

  return {
    nit,
    check_digit: typeof body.check_digit === 'string' ? body.check_digit : null,
    legal_name: typeof body.legal_name === 'string' ? body.legal_name : null,
    software: software && typeof software.id === 'string' && typeof software.pin === 'string' ? { id: software.id, pin: software.pin } : null,
    ranges: asRanges(body.ranges ?? []),
    registered_at: new Date().toISOString(),
  }
}

function asMode(body: Record<string, unknown>): Mode {
  const name = body.mode as ModeName

  if (!MODE_NAMES.includes(name)) {
    throw new HttpError(422, `mode must be one of ${MODE_NAMES.join(', ')}.`)
  }

  const count = body.count === null || body.count === undefined ? null : Number(body.count)

  if (count !== null && (!Number.isInteger(count) || count < 1)) {
    throw new HttpError(422, 'count must be a whole number of requests, 1 or more.')
  }

  const mode: Mode = { ...normalMode(), mode: name, remaining: name === 'normal' ? null : count }

  if (name === 'reject') {
    const chosen = typeof body.rule === 'string' ? rule(body.rule) : undefined

    if (!chosen || chosen.kind !== 'rejection') {
      throw new HttpError(422, 'rule must be the code of a rejection rule the simulator implements.')
    }

    mode.rule = chosen.code
  }

  if (name === 'slow') {
    const delay = Number(body.delay_ms)

    if (!Number.isInteger(delay) || delay < 0 || delay > 600_000) {
      throw new HttpError(422, 'delay_ms must be between 0 and 600000.')
    }

    mode.delay_ms = delay
  }

  if (name === 'intermittent') {
    const rate = Number(body.failure_rate)

    if (!Number.isInteger(rate) || rate < 1 || rate > 100) {
      throw new HttpError(422, 'failure_rate must be a percentage between 1 and 100.')
    }

    mode.failure_rate = rate
  }

  return mode
}

function publicTaxpayer(taxpayer: Taxpayer): unknown {
  return {
    nit: taxpayer.nit,
    legal_name: taxpayer.legal_name,
    software_id: taxpayer.software?.id ?? null,
    pin_set: taxpayer.software !== null,
    ranges: taxpayer.ranges.map(({ technical_key: _key, ...range }) => range),
    registered_at: taxpayer.registered_at,
  }
}

async function admin(request: IncomingMessage, response: ServerResponse, url: URL): Promise<void> {
  if (TOKEN !== '' && request.headers['x-simulator-token'] !== TOKEN) {
    throw new HttpError(401, 'Send the admin token in X-Simulator-Token.')
  }

  const method = request.method ?? 'GET'
  const [, , resource, id] = url.pathname.split('/')
  const body = method === 'PUT' || method === 'POST' ? JSON.parse((await readBody(request)) || '{}') as Record<string, unknown> : {}

  if (resource === 'rules' && method === 'GET') {
    return json(response, 200, { rules: RULES })
  }

  if (resource === 'taxpayers' && id) {
    if (!/^\d{5,15}$/.test(id)) {
      throw new HttpError(422, 'The NIT has only digits, without the check digit.')
    }

    if (method === 'PUT') {
      state.taxpayers[id] = asTaxpayer(id, body)
      store.save()

      return json(response, 200, publicTaxpayer(state.taxpayers[id]))
    }

    if (method === 'GET') {
      return state.taxpayers[id] ? json(response, 200, publicTaxpayer(state.taxpayers[id])) : json(response, 404, { error: 'Unknown taxpayer.' })
    }

    if (method === 'DELETE') {
      delete state.taxpayers[id]
      delete state.modes[id]

      for (const [key, record] of Object.entries(state.documents)) {
        if (record.nit === id) {
          delete state.documents[key]
        }
      }

      state.requests = state.requests.filter((entry) => entry.nit !== id)
      store.save()

      return json(response, 200, { deleted: id })
    }
  }

  if (resource === 'modes' && id) {
    if (method === 'GET') {
      return json(response, 200, { nit: id, ...currentMode(state, id) })
    }

    if (method === 'PUT') {
      const mode = asMode(body)

      if (mode.mode === 'normal') {
        delete state.modes[id]
      } else {
        state.modes[id] = mode
      }

      store.save()

      return json(response, 200, { nit: id, ...currentMode(state, id) })
    }
  }

  if (resource === 'requests' && method === 'GET') {
    const nit = url.searchParams.get('nit')
    const limit = Math.min(500, Math.max(1, Number(url.searchParams.get('limit') ?? 50) || 50))

    return json(response, 200, { requests: state.requests.filter((entry) => nit === null || entry.nit === nit).slice(0, limit) })
  }

  if (resource === 'documents' && method === 'GET') {
    const nit = url.searchParams.get('nit')
    const documents = Object.values(state.documents)
      .filter((record) => nit === null || record.nit === nit)
      .map((record) => ({ ...record, rules: record.findings.map((item) => item.code), findings: undefined }))

    return json(response, 200, { documents })
  }

  if (resource === 'reset' && method === 'POST') {
    store.reset()

    return json(response, 200, { reset: true })
  }

  throw new HttpError(404, 'Unknown admin endpoint.')
}

// ---------------------------------------------------------------------------------------------
// Server

async function route(request: IncomingMessage, response: ServerResponse): Promise<void> {
  const url = new URL(request.url ?? '/', 'http://simulator')
  const path = url.pathname.toLowerCase()

  if (request.method === 'GET' && path === '/health') {
    return json(response, 200, {
      status: 'ok',
      service: 'Local DIAN simulator: a development tool, not the DIAN',
      taxpayers: Object.keys(state.taxpayers).length,
      documents: Object.keys(state.documents).length,
      modes: Object.keys(state.modes).length,
      all: state.modes[ALL] ?? null,
    })
  }

  if (path.startsWith('/admin/')) {
    return admin(request, response, url)
  }

  if (path.endsWith('.svc')) {
    if (request.method === 'POST') {
      return soap(request, response)
    }

    response.writeHead(200, { 'content-type': 'text/plain; charset=utf-8' })
    response.end('Local DIAN simulator (development tool, not the DIAN). POST SOAP 1.2 requests for SendBillSync or GetStatus here.\n')

    return
  }

  throw new HttpError(404, 'Not found.')
}

const server = createServer((request, response) => {
  route(request, response).catch((error: unknown) => {
    const status = error instanceof HttpError ? error.status : (error instanceof SyntaxError ? 400 : 500)

    if (status === 500) {
      console.error(error)
    }

    if (!response.headersSent && !response.destroyed) {
      json(response, status, { error: error instanceof Error ? error.message : 'Unexpected error.' })
    }
  })
})

server.on('connection', (socket: Socket) => {
  sockets.add(socket)
  socket.on('close', () => sockets.delete(socket))
})

function stop(signal: string): void {
  console.log(`${signal}: stopping the local DIAN simulator.`)
  store.save()
  server.close()

  for (const socket of sockets) {
    socket.destroy()
  }

  setTimeout(() => process.exit(0), 100).unref()
}

process.on('SIGTERM', () => stop('SIGTERM'))
process.on('SIGINT', () => stop('SIGINT'))

server.listen(PORT, () => {
  console.log(`Local DIAN simulator listening on port ${PORT} (${Object.keys(state.taxpayers).length} taxpayers, ${Object.keys(state.documents).length} documents).`)
})
