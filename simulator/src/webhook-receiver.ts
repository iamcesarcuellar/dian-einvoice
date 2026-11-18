// Local webhook receiver, a development tool of dian-einvoice inside the simulator service. It
// stands in for the endpoint of an integration: it receives webhooks, verifies their Standard
// Webhooks signature with the secrets the backend registers, and keeps the last ones it got with
// the result of the check. Its mode can make it answer HTTP 500, or slowly, to the next N deliveries.
//
//   POST /webhook-receiver/{company}                     a webhook
//   GET  /admin/webhook-receiver/{company}?limit=        mode and the last webhooks, newest first
//   PUT  /admin/webhook-receiver/{company}/mode          { mode, count, delay_ms }
//   PUT  /admin/webhook-receiver/{company}/secrets/{id}  { secret }, the secret of a subscription
//   DELETE /admin/webhook-receiver/{company}/secrets/{id}
//   DELETE /admin/webhook-receiver/{company}             forget the company
import { createHmac, timingSafeEqual } from 'node:crypto'
import type { ServerResponse } from 'node:http'
import type { ReceivedWebhook, ReceiverCompany, ReceiverMode, ReceiverModeName, State } from './state.ts'

export const RECEIVER_MODES: readonly ReceiverModeName[] = ['normal', 'error_500', 'slow']

/** Webhooks kept per company. */
const KEEP = 200

/** Seconds a webhook-timestamp may differ from now, as Standard Webhooks suggests. */
const TOLERANCE_SECONDS = 5 * 60

/** Delay of the slow mode when none is given: above the 5 second timeout of the sender. */
const DEFAULT_SLOW_MS = 7000

const BODY_PREVIEW = 4000

export class ReceiverError extends Error {
  readonly status: number

  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

function normalMode(): ReceiverMode {
  return { mode: 'normal', remaining: null, delay_ms: null, updated_at: new Date().toISOString() }
}

function companyOf(state: State, id: string): ReceiverCompany {
  state.receiver ??= {}
  state.receiver[id] ??= { secrets: {}, mode: normalMode(), received: [] }

  return state.receiver[id]
}

function validCompany(id: string | undefined): string {
  if (!id || !/^[0-9a-z]{26}$/i.test(id)) {
    throw new ReceiverError(404, 'Unknown receiver: the path ends with the id of a company.')
  }

  return id.toLowerCase()
}

/** The mode that applies to one delivery, counting it down. */
function takeMode(company: ReceiverCompany): ReceiverMode {
  const mode = { ...company.mode }

  if (company.mode.mode !== 'normal' && company.mode.remaining !== null) {
    company.mode.remaining -= 1

    if (company.mode.remaining <= 0) {
      company.mode = normalMode()
    }
  }

  return mode
}

/** The signature check of Standard Webhooks against every secret of the company. */
function verify(company: ReceiverCompany, id: string | undefined, timestamp: string | undefined, signature: string | undefined, body: string): string {
  if (!id || !timestamp || !signature) {
    return 'missing_headers'
  }

  const seconds = Number(timestamp)

  if (!Number.isInteger(seconds) || Math.abs(Date.now() / 1000 - seconds) > TOLERANCE_SECONDS) {
    return 'timestamp_out_of_tolerance'
  }

  const secrets = Object.values(company.secrets)

  if (secrets.length === 0) {
    return 'no_secret'
  }

  const signed = `${id}.${timestamp}.${body}`
  const offered = signature.split(/\s+/).filter((part) => part.startsWith('v1,')).map((part) => Buffer.from(part.slice(3), 'base64'))

  for (const secret of secrets) {
    const key = Buffer.from(secret.startsWith('whsec_') ? secret.slice(6) : secret, 'base64')
    const expected = createHmac('sha256', key).update(signed, 'utf8').digest()

    if (offered.some((candidate) => candidate.length === expected.length && timingSafeEqual(candidate, expected))) {
      return 'valid'
    }
  }

  return 'invalid_signature'
}

function header(value: string | string[] | undefined): string | undefined {
  return Array.isArray(value) ? value[0] : value
}

function sleep(milliseconds: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, milliseconds))
}

/**
 * Receives one webhook: verifies it, keeps it, and answers as the mode says. In slow mode the
 * answer comes after the delay, so the sender gives up first; the webhook is kept all the same.
 */
export async function receive(
  state: State,
  companyId: string | undefined,
  headers: Record<string, string | string[] | undefined>,
  body: string,
  response: ServerResponse,
  save: () => void,
): Promise<void> {
  const id = validCompany(companyId)
  const company = companyOf(state, id)
  const mode = takeMode(company)
  const webhookId = header(headers['webhook-id'])
  const verification = verify(company, webhookId, header(headers['webhook-timestamp']), header(headers['webhook-signature']), body)

  let parsed: { id?: unknown, type?: unknown, data?: { object?: { id?: unknown } } } = {}

  try {
    parsed = JSON.parse(body) as typeof parsed
  } catch {
    parsed = {}
  }

  const status = mode.mode === 'error_500' ? 500 : 200
  const entry: ReceivedWebhook = {
    received_at: new Date().toISOString(),
    webhook_id: webhookId ?? null,
    timestamp: header(headers['webhook-timestamp']) ?? null,
    event_id: typeof parsed.id === 'string' ? parsed.id : null,
    event_type: typeof parsed.type === 'string' ? parsed.type : null,
    object_id: typeof parsed.data?.object?.id === 'string' ? parsed.data.object.id : null,
    verified: verification === 'valid',
    verification,
    mode: mode.mode,
    response_status: status,
    delay_ms: mode.mode === 'slow' ? (mode.delay_ms ?? DEFAULT_SLOW_MS) : null,
    size: Buffer.byteLength(body, 'utf8'),
    body_preview: body.length > BODY_PREVIEW ? `${body.slice(0, BODY_PREVIEW)}…` : body,
  }

  company.received.unshift(entry)

  if (company.received.length > KEEP) {
    company.received.length = KEEP
  }

  save()
  console.log(`webhook company=${id} type=${entry.event_type ?? '-'} id=${entry.webhook_id ?? '-'} verification=${verification} answer=${status}${mode.mode === 'slow' ? ` after ${entry.delay_ms} ms` : ''}`)

  if (mode.mode === 'slow') {
    await sleep(entry.delay_ms ?? DEFAULT_SLOW_MS)
  }

  if (response.destroyed) {
    return
  }

  response.writeHead(status, { 'content-type': 'application/json; charset=utf-8' })
  response.end(JSON.stringify(status === 200
    ? { received: true, verification }
    : { error: 'Simulated error of the local webhook receiver (mode error_500).' }))
}

function asMode(body: Record<string, unknown>): ReceiverMode {
  const name = body.mode as ReceiverModeName

  if (!RECEIVER_MODES.includes(name)) {
    throw new ReceiverError(422, `mode must be one of ${RECEIVER_MODES.join(', ')}.`)
  }

  const count = body.count === null || body.count === undefined ? null : Number(body.count)

  if (count !== null && (!Number.isInteger(count) || count < 1)) {
    throw new ReceiverError(422, 'count must be a whole number of deliveries, 1 or more.')
  }

  const delay = body.delay_ms === null || body.delay_ms === undefined ? DEFAULT_SLOW_MS : Number(body.delay_ms)

  if (name === 'slow' && (!Number.isInteger(delay) || delay < 0 || delay > 600_000)) {
    throw new ReceiverError(422, 'delay_ms must be between 0 and 600000.')
  }

  return {
    mode: name,
    remaining: name === 'normal' ? null : count,
    delay_ms: name === 'slow' ? delay : null,
    updated_at: new Date().toISOString(),
  }
}

/** The admin API of the receiver; segments come after /admin/webhook-receiver/. */
export function admin(state: State, method: string, segments: string[], url: URL, body: Record<string, unknown>, save: () => void): unknown {
  const [companyId, resource, subscriptionId] = segments
  const id = validCompany(companyId)

  if (resource === undefined && method === 'GET') {
    const company = companyOf(state, id)
    const limit = Math.min(KEEP, Math.max(1, Number(url.searchParams.get('limit') ?? 30) || 30))

    return { mode: company.mode, secrets: Object.keys(company.secrets).length, received: company.received.slice(0, limit) }
  }

  if (resource === undefined && method === 'DELETE') {
    if (state.receiver) {
      delete state.receiver[id]
    }

    save()

    return { deleted: id }
  }

  if (resource === 'mode' && method === 'PUT') {
    const company = companyOf(state, id)
    company.mode = asMode(body)
    save()

    return { mode: company.mode }
  }

  if (resource === 'secrets' && subscriptionId) {
    const company = companyOf(state, id)

    if (method === 'PUT') {
      if (typeof body.secret !== 'string' || !body.secret.startsWith('whsec_')) {
        throw new ReceiverError(422, 'secret must be a whsec_ secret.')
      }

      company.secrets[subscriptionId] = body.secret
      save()

      return { registered: subscriptionId, secrets: Object.keys(company.secrets).length }
    }

    if (method === 'DELETE') {
      delete company.secrets[subscriptionId]
      save()

      return { forgotten: subscriptionId, secrets: Object.keys(company.secrets).length }
    }
  }

  throw new ReceiverError(404, 'Unknown endpoint of the webhook receiver.')
}
