// Local simulator of the DIAN web service for electronic invoicing, a development tool of
// dian-einvoice. It is not the DIAN: nothing it accepts is registered anywhere.
//
//   POST /WcfDianCustomerServices.svc   SOAP 1.2: SendBillSync and GetStatus (annex 1.9, section 7)
//   POST /webhook-receiver/{company}    the local webhook receiver, another development tool
//   GET  /health
//   /admin/...                          taxpayers, failure modes, activity, the webhook receiver (X-Simulator-Token)
//
// Those paths serve the local stack. The emulated cloud uses the same paths under /cloud, with records of its own
// (src/stacks.ts): /cloud/WcfDianCustomerServices.svc, /cloud/admin/..., /cloud/webhook-receiver/{company}.
//
// Like the DIAN's web service, it responds in Spanish: the rules' messages (src/rules.ts) and its own texts, from
// SOAP faults to admin API errors. Codes and log lines stay in English.
import { timingSafeEqual } from 'node:crypto'
import { createServer } from 'node:http'
import type { IncomingMessage, ServerResponse } from 'node:http'
import type { Socket } from 'node:net'
import { join } from 'node:path'
import { unzipSync } from 'fflate'
import { applicationResponse } from './application-response.ts'
import { readDocument, schemaPaths } from './document.ts'
import type { DocumentKind } from './document.ts'
import { ALL, MODE_NAMES, currentMode, normalMode, takeMode } from './modes.ts'
import { RULES, finding, formatFinding, rule } from './rules.ts'
import type { Finding } from './rules.ts'
import { faultEnvelope, parseRequest, resultEnvelope } from './soap.ts'
import type { DianResult, SoapRequest } from './soap.ts'
import { PREFIXES, STACKS, openStacks, stackOf } from './stacks.ts'
import type { Stack } from './stacks.ts'
import type { DocumentRecord, Mode, ModeName, Range, RequestLog, Taxpayer } from './state.ts'
import { validateDocument } from './validate.ts'
import { ReceiverError, admin as receiverAdmin, receive } from './webhook-receiver.ts'
import { parseXml } from './xml.ts'

const PORT = Number(process.env.PORT ?? 8602)
/** The admin token: configuration only (compose passes DIAN_SIMULATOR_TOKEN of backend/.env). */
const TOKEN = process.env.SIMULATOR_ADMIN_TOKEN ?? ''

if (TOKEN === '') {
  console.error('SIMULATOR_ADMIN_TOKEN is empty: the admin API would be open. Run make up, which writes a token to backend/.env.')
  process.exit(1)
}
/** The official XSDs: the xsd folder of the image, or SIMULATOR_XSD_DIR. */
const SCHEMAS = schemaPaths(process.env.SIMULATOR_XSD_DIR ?? join(import.meta.dirname, '..', 'xsd'))

/** How the DIAN names each kind in the answer of an authorized document. */
const AUTHORIZED: Record<DocumentKind, string> = {
  invoice: 'La Factura electrónica',
  credit_note: 'La Nota Crédito electrónica',
  debit_note: 'La Nota Débito electrónica',
}
const MAX_BODY = 20 * 1024 * 1024
/**
 * Limits of the zip of SendBillSync, which carries one document: its size, the size its entries
 * declare once uncompressed (each one and all together, so a small zip cannot expand into gigabytes;
 * fflate never writes past the declared size), how many entries it has, and plain entry names.
 */
const MAX_ZIP_BYTES = 10 * 1024 * 1024
const MAX_ENTRY_BYTES = 10 * 1024 * 1024
const MAX_UNZIPPED_BYTES = 20 * 1024 * 1024
const MAX_ENTRIES = 10
/** A name without folders: no slash, backslash, colon or "..", and no control characters. */
const ENTRY_NAME = /^(?!.*\.\.)[A-Za-z0-9_][A-Za-z0-9._-]{0,99}$/
const REQUEST_LOG = 500
/** How long a request in no_response mode is held open before the socket is dropped. */
const HOLD_MS = 5 * 60 * 1000

const stacks = openStacks(process.env.SIMULATOR_DATA ?? '/data')
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
        reject(new HttpError(413, 'La petición es demasiado grande.'))
        request.destroy()

        return
      }

      chunks.push(chunk)
    })
    request.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')))
    request.on('error', reject)
  })
}

/**
 * A JSON object, or nothing: an empty body or an empty list, which is how PHP encodes an empty array.
 * Any other JSON (null, a list with items, a number, a string) is a 400.
 */
function jsonObject(text: string): Record<string, unknown> {
  if (text.trim() === '') {
    return {}
  }

  const parsed: unknown = JSON.parse(text)

  if (Array.isArray(parsed) && parsed.length === 0) {
    return {}
  }

  if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
    throw new HttpError(400, 'El cuerpo debe ser un objeto JSON.')
  }

  return parsed as Record<string, unknown>
}

/** The admin token, compared in constant time. */
function validToken(sent: string | string[] | undefined): boolean {
  const offered = Buffer.from(typeof sent === 'string' ? sent : '', 'utf8')
  const expected = Buffer.from(TOKEN, 'utf8')

  return offered.length === expected.length && timingSafeEqual(offered, expected)
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

function log(stack: Stack, entry: Omit<RequestLog, 'at'>): void {
  const { requests } = stack.state
  requests.unshift({ at: new Date().toISOString(), ...entry })

  if (requests.length > REQUEST_LOG) {
    requests.length = REQUEST_LOG
  }

  stack.store.save()
  console.log(`${entry.operation} stack=${stack.name} nit=${entry.nit ?? '-'} document=${entry.document ?? '-'} result=${entry.result}${entry.mode ? ` mode=${entry.mode}` : ''} ${entry.duration_ms} ms`)
}

// ---------------------------------------------------------------------------------------------
// SOAP: SendBillSync and GetStatus

/**
 * Names of annex 1.9: the zip (6.5.8) is z + the NIT in ten digits + ppp (three digits) + aa (two
 * digits of the year) + dddddddd (eight hexadecimal digits, 00000001 to FFFFFFFF); the XML inside
 * (6.5.7) has the same parts after fv, nc or nd.
 */
const ZIP_NAME = /^z(\d{10})(\d{3})(\d{2})([0-9A-F]{8})\.zip$/
const XML_NAME = /^(fv|nc|nd)(\d{10})(\d{3})(\d{2})([0-9A-F]{8})\.xml$/

/** The prefix of 6.5.7 of each kind. */
const FILE_PREFIX: Record<DocumentKind, string> = { invoice: 'fv', credit_note: 'nc', debit_note: 'nd' }

/** The NIT in a zip name of annex 1.9, 6.5.8. */
function nitFromFileName(fileName: string | undefined): string | null {
  return ZIP_NAME.exec(fileName ?? '')?.[1]?.replace(/^0+/, '') ?? null
}

/**
 * The zip and XML names against 6.5.8 and 6.5.7: their format, a consecutive above zero, the NIT
 * of the issuer in both and the prefix of the kind of document. The annex gives these no rule code
 * or message: the texts are the simulator's own.
 */
function nameFindings(zipName: string | undefined, xmlName: string, kind: DocumentKind, supplierNit: string): Finding[] {
  const zip = ZIP_NAME.exec(zipName ?? '')
  const xml = XML_NAME.exec(xmlName)
  const nit = supplierNit.padStart(10, '0')

  if (!zip || zip[4] === '00000000' || zip[1] !== nit) {
    return [{ code: null, kind: 'rejection', message: `El nombre del archivo ZIP "${zipName ?? ''}" no sigue el numeral 6.5.8 del anexo técnico: z, NIT del facturador de diez dígitos, código de tres dígitos, año de dos dígitos y consecutivo de ocho dígitos hexadecimales (00000001 a FFFFFFFF).` }]
  }

  if (!xml || xml[5] === '00000000' || xml[2] !== nit || xml[1] !== FILE_PREFIX[kind]) {
    return [{ code: null, kind: 'rejection', message: `El nombre del documento "${xmlName}" no sigue el numeral 6.5.7 del anexo técnico: ${FILE_PREFIX[kind]}, NIT del facturador de diez dígitos, código de tres dígitos, año de dos dígitos y consecutivo de ocho dígitos hexadecimales (00000001 a FFFFFFFF).` }]
  }

  return []
}

function resultOf(record: DocumentRecord): DianResult {
  const accepted = record.status === 'accepted'

  return {
    isValid: accepted,
    statusCode: accepted ? '00' : '99',
    statusDescription: accepted ? 'Procesado Correctamente.' : 'Validación contiene errores en campos mandatorios.',
    statusMessage: accepted ? `${AUTHORIZED[record.kind ?? 'invoice']} ${record.number}, ha sido autorizada.` : 'Documento con errores en campos mandatorios.',
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

async function sendBillSync(stack: Stack, request: SoapRequest, mode: Mode | null): Promise<Answer> {
  const { state, store } = stack
  const zip = Buffer.from(request.contentFile ?? '', 'base64')

  const refuse = (message: string): Answer => ({ result: unreadable([{ code: null, kind: 'rejection', message }]), outcome: 'rejected', document: null, rules: [] })

  if (zip.length === 0) {
    return refuse('El archivo ZIP está vacío.')
  }

  if (zip.length > MAX_ZIP_BYTES) {
    return refuse(`El archivo ZIP supera ${MAX_ZIP_BYTES / 1024 / 1024} MB.`)
  }

  let entries: Record<string, Uint8Array>
  let refusal: string | null = null
  let count = 0
  let unzipped = 0

  try {
    entries = unzipSync(new Uint8Array(zip), {
      // Before anything is uncompressed: the count, the names and the declared sizes.
      filter: (file) => {
        count += 1
        unzipped += file.originalSize

        if (refusal !== null) {
          return false
        }

        if (count > MAX_ENTRIES) {
          refusal = `El archivo ZIP contiene más de ${MAX_ENTRIES} archivos.`
        } else if (!ENTRY_NAME.test(file.name)) {
          refusal = 'Un nombre de archivo dentro del ZIP no es válido: sin carpetas, rutas ni "..".'
        } else if (file.originalSize > MAX_ENTRY_BYTES || unzipped > MAX_UNZIPPED_BYTES) {
          refusal = `El contenido del ZIP supera ${MAX_ENTRY_BYTES / 1024 / 1024} MB por archivo o ${MAX_UNZIPPED_BYTES / 1024 / 1024} MB en total.`
        }

        return refusal === null
      },
    })
  } catch {
    return refuse('El archivo ZIP está corrupto.')
  }

  if (refusal !== null) {
    return refuse(refusal)
  }

  const names = Object.keys(entries).filter((name) => name.toLowerCase().endsWith('.xml'))
  const xmlFileName = names[0]

  if (xmlFileName === undefined || names.length > 1) {
    return { result: unreadable([{ code: null, kind: 'rejection', message: xmlFileName === undefined ? 'El archivo ZIP no contiene un documento XML.' : 'El archivo ZIP contiene más de un documento XML.' }]), outcome: 'rejected', document: null, rules: [] }
  }

  const xml = new TextDecoder('utf-8').decode(entries[xmlFileName])
  const parsed = parseXml(xml)

  if (!parsed) {
    return { result: unreadable([finding('ZB01', 'el XML no está bien formado')]), outcome: 'rejected', document: null, rules: ['ZB01'] }
  }

  const invoice = readDocument(parsed)
  const key = invoice.cufe?.toLowerCase()
  const kind = invoice.kind ?? 'invoice'

  if (!key || !invoice.number || !invoice.supplierNit || !invoice.issueDate || !invoice.customerId || invoice.ublVersion !== 'UBL 2.1') {
    return { result: unreadable([finding('ZB01', 'el CUFE o el CUDE, el número, la fecha de emisión, el emisor y el adquiriente son obligatorios, y UBLVersionID debe ser UBL 2.1')]), outcome: 'rejected', document: invoice.number ?? null, rules: ['ZB01'] }
  }

  const badNames = nameFindings(request.fileName, xmlFileName, kind, invoice.supplierNit)

  if (badNames.length > 0) {
    return { result: unreadable(badNames, key), outcome: 'rejected', document: invoice.number, rules: [] }
  }

  // The same document again (annex 1.9, rule 90: a number is transmitted only once per issuer):
  // rejected, and the accepted one stays as it was; GetStatus with its CUFE or CUDE returns it.
  const previous = state.documents[key]

  if (previous?.status === 'accepted') {
    previous.submissions += 1
    store.save()

    return { result: unreadable([finding('90')], key), outcome: 'duplicate', document: previous.number, rules: ['90'] }
  }

  // Another document of the same type with a number already accepted for this issuer.
  const sameNumber = Object.values(state.documents).find((record) => record.status === 'accepted' && (record.kind ?? 'invoice') === kind && record.nit === invoice.supplierNit && record.number === invoice.number)

  if (sameNumber) {
    return { result: unreadable([finding('90')], key), outcome: 'rejected', document: invoice.number, rules: ['90'] }
  }

  const findings = await validateDocument(xml, invoice, state, SCHEMAS)

  // A copy sent at the same time finished first: this one is a repetition of it (rule 90).
  const meanwhile = state.documents[key]

  if (meanwhile !== undefined && meanwhile !== previous && meanwhile.status === 'accepted') {
    meanwhile.submissions += 1
    store.save()

    return { result: unreadable([finding('90')], key), outcome: 'duplicate', document: meanwhile.number, rules: ['90'] }
  }

  if (mode?.mode === 'reject' && mode.rule) {
    findings.push(finding(mode.rule, undefined, true))
  }

  const accepted = !findings.some((item) => item.kind === 'rejection')
  const now = new Date().toISOString()
  const record: DocumentRecord = {
    cufe: key,
    kind,
    references: kind === 'invoice' ? null : (invoice.reference?.cufe?.toLowerCase() ?? null),
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

function getStatus(stack: Stack, request: SoapRequest): Answer {
  const trackId = (request.trackId ?? '').toLowerCase()
  const record = stack.state.documents[trackId]

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

async function soap(stack: Stack, request: IncomingMessage, response: ServerResponse): Promise<void> {
  const started = performance.now()
  const elapsed = () => Math.round(performance.now() - started)
  const envelope = parseRequest(await readBody(request))

  if (!envelope || (envelope.operation !== 'SendBillSync' && envelope.operation !== 'GetStatus')) {
    soapResponse(response, 400, faultEnvelope('Sender', envelope ? `El simulador local de la DIAN no implementa ${envelope.operation}; ofrece SendBillSync y GetStatus.` : 'La petición no es un sobre SOAP 1.2.'))
    log(stack, { operation: envelope?.operation ?? 'unknown', nit: null, document: null, result: 'invalid_request', status_code: null, duration_ms: elapsed(), mode: null, rules: [] })

    return
  }

  const operation = envelope.operation
  const nit = operation === 'SendBillSync' ? nitFromFileName(envelope.fileName) : (stack.state.documents[(envelope.trackId ?? '').toLowerCase()]?.nit ?? null)
  const mode = takeMode(stack.state, nit, operation)

  if (mode !== null) {
    stack.store.save()
  }

  const fail = (how: 'error_500' | 'reset', label: string): void => {
    if (how === 'error_500') {
      soapResponse(response, 500, faultEnvelope('Receiver', `Error interno forzado en el simulador local de la DIAN (${label}).`))
    } else {
      request.socket.destroy()
    }

    log(stack, { operation, nit, document: envelope.trackId ?? envelope.fileName ?? null, result: how, status_code: null, duration_ms: elapsed(), mode: label, rules: [] })
  }

  if (mode?.mode === 'error_500') {
    fail('error_500', 'error_500')

    return
  }

  if (mode?.mode === 'intermittent' && Math.random() * 100 < (mode.failure_rate ?? 0)) {
    fail(Math.random() < 0.5 ? 'error_500' : 'reset', 'intermittent')

    return
  }

  const answer = operation === 'SendBillSync' ? await sendBillSync(stack, envelope, mode) : getStatus(stack, envelope)

  if (mode?.mode === 'slow') {
    await sleep(mode.delay_ms ?? 0)
  }

  const entry = { operation, nit: nit ?? null, document: answer.document, status_code: answer.result.statusCode, mode: mode?.mode ?? null, rules: answer.rules }

  if (mode?.mode === 'no_response') {
    // Processed, but the answer never leaves: as if it were lost on the way back.
    const timer = setTimeout(() => request.socket.destroy(), HOLD_MS)
    request.socket.once('close', () => clearTimeout(timer))
    log(stack, { ...entry, result: `${answer.outcome} (response withheld)`, duration_ms: elapsed() })

    return
  }

  soapResponse(response, 200, resultEnvelope(operation, answer.result))
  log(stack, { ...entry, result: answer.outcome, duration_ms: elapsed() })
}

// ---------------------------------------------------------------------------------------------
// Admin API

function asRanges(value: unknown): Range[] {
  if (!Array.isArray(value)) {
    throw new HttpError(422, 'ranges debe ser una lista.')
  }

  return value.map((item: Record<string, unknown>) => {
    if (typeof item?.resolution_number !== 'string' || typeof item.technical_key !== 'string' || !Number.isInteger(item.from) || !Number.isInteger(item.to)) {
      throw new HttpError(422, 'Cada rango necesita resolution_number, from, to y technical_key.')
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
    throw new HttpError(422, `mode debe ser uno de estos: ${MODE_NAMES.join(', ')}.`)
  }

  const count = body.count === null || body.count === undefined ? null : Number(body.count)

  if (count !== null && (!Number.isInteger(count) || count < 1)) {
    throw new HttpError(422, 'count debe ser un número entero de peticiones, 1 o más.')
  }

  const mode: Mode = { ...normalMode(), mode: name, remaining: name === 'normal' ? null : count }

  if (name === 'reject') {
    const chosen = typeof body.rule === 'string' ? rule(body.rule) : undefined

    if (!chosen || chosen.kind !== 'rejection') {
      throw new HttpError(422, 'rule debe ser el código de una regla de rechazo que el simulador implemente.')
    }

    mode.rule = chosen.code
  }

  if (name === 'slow') {
    const delay = Number(body.delay_ms)

    if (!Number.isInteger(delay) || delay < 0 || delay > 600_000) {
      throw new HttpError(422, 'delay_ms debe estar entre 0 y 600000.')
    }

    mode.delay_ms = delay
  }

  if (name === 'intermittent') {
    const rate = Number(body.failure_rate)

    if (!Number.isInteger(rate) || rate < 1 || rate > 100) {
      throw new HttpError(422, 'failure_rate debe ser un porcentaje entre 1 y 100.')
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
    // Every field but the technical key, which stays secret.
    ranges: taxpayer.ranges.map((range) => ({
      resolution_number: range.resolution_number,
      prefix: range.prefix,
      from: range.from,
      to: range.to,
      valid_from: range.valid_from,
      valid_to: range.valid_to,
    })),
    registered_at: taxpayer.registered_at,
  }
}

async function admin(stack: Stack, request: IncomingMessage, response: ServerResponse, url: URL): Promise<void> {
  if (!validToken(request.headers['x-simulator-token'])) {
    throw new HttpError(401, 'Envía el token de administración en X-Simulator-Token.')
  }

  const method = request.method ?? 'GET'
  const [, , resource, ...segments] = url.pathname.split('/')
  const id = segments[0]
  const body = method === 'PUT' || method === 'POST' ? jsonObject(await readBody(request)) : {}
  const { state, store } = stack

  if (resource === 'webhook-receiver') {
    return json(response, 200, receiverAdmin(state, method, segments.filter((part) => part !== ''), url, body, () => store.save()))
  }

  if (resource === 'rules' && method === 'GET') {
    return json(response, 200, { rules: RULES })
  }

  if (resource === 'taxpayers' && id) {
    if (!/^\d{5,15}$/.test(id)) {
      throw new HttpError(422, 'El NIT lleva solo dígitos, sin el dígito de verificación.')
    }

    if (method === 'PUT') {
      state.taxpayers[id] = asTaxpayer(id, body)
      store.save()

      return json(response, 200, publicTaxpayer(state.taxpayers[id]))
    }

    if (method === 'GET') {
      return state.taxpayers[id] ? json(response, 200, publicTaxpayer(state.taxpayers[id])) : json(response, 404, { error: 'Contribuyente desconocido.' })
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

  throw new HttpError(404, 'Ruta de administración desconocida.')
}

// ---------------------------------------------------------------------------------------------
// Server

/** The records of one stack, at a glance; /health of each stack names where the others are. */
function health(stack: Stack): unknown {
  const { state } = stack

  return {
    status: 'ok',
    service: 'Simulador local de la DIAN: una herramienta de desarrollo, no la DIAN',
    stack: stack.name,
    taxpayers: Object.keys(state.taxpayers).length,
    documents: Object.keys(state.documents).length,
    modes: Object.keys(state.modes).length,
    all: state.modes[ALL] ?? null,
    webhook_receiver: { companies: Object.keys(state.receiver ?? {}).length },
    stacks: Object.fromEntries(STACKS.map((name) => [name, `${PREFIXES[name]}/health`])),
  }
}

async function route(request: IncomingMessage, response: ServerResponse): Promise<void> {
  const requested = new URL(request.url ?? '/', 'http://simulator')
  const { name, path: inner } = stackOf(requested.pathname)
  const stack = stacks[name]
  const url = new URL(`${inner}${requested.search}`, 'http://simulator')
  const path = url.pathname.toLowerCase()

  if (request.method === 'GET' && path === '/health') {
    return json(response, 200, health(stack))
  }

  if (path.startsWith('/admin/')) {
    return admin(stack, request, response, url)
  }

  if (path.startsWith('/webhook-receiver/') && request.method === 'POST') {
    const [, , companyId] = url.pathname.split('/')

    return receive(stack.state, companyId, request.headers, await readBody(request), response, () => stack.store.save())
  }

  if (path.endsWith('.svc')) {
    if (request.method === 'POST') {
      return soap(stack, request, response)
    }

    response.writeHead(200, { 'content-type': 'text/plain; charset=utf-8' })
    response.end('Simulador local de la DIAN (herramienta de desarrollo, no es la DIAN). Envía aquí peticiones SOAP 1.2 de SendBillSync o GetStatus.\n')

    return
  }

  throw new HttpError(404, 'No encontrado.')
}

const server = createServer((request, response) => {
  route(request, response).catch((error: unknown) => {
    const status = error instanceof HttpError || error instanceof ReceiverError ? error.status : (error instanceof SyntaxError ? 400 : 500)

    if (status === 500) {
      console.error(error)
    }

    if (!response.headersSent && !response.destroyed) {
      // A 500 says nothing of the code; a 4xx says what to fix.
      json(response, status, { error: status === 500 ? 'Error inesperado del simulador local de la DIAN.' : (error instanceof Error ? error.message : 'Petición inválida.') })
    }
  })
})

server.on('connection', (socket: Socket) => {
  sockets.add(socket)
  socket.on('close', () => sockets.delete(socket))
})

function stop(signal: string): void {
  console.log(`${signal}: stopping the local DIAN simulator.`)

  for (const name of STACKS) {
    stacks[name].store.save()
  }

  server.close()

  for (const socket of sockets) {
    socket.destroy()
  }

  setTimeout(() => process.exit(0), 100).unref()
}

process.on('SIGTERM', () => stop('SIGTERM'))
process.on('SIGINT', () => stop('SIGINT'))

server.listen(PORT, () => {
  const counts = STACKS.map((name) => `${name}: ${Object.keys(stacks[name].state.taxpayers).length} taxpayers, ${Object.keys(stacks[name].state.documents).length} documents`)
  console.log(`Local DIAN simulator listening on port ${PORT} (${counts.join('; ')}).`)
})
