// The structured log line of the TypeScript functions (the origin verifier and the notifier): one JSON object per
// line with exactly the fields of the schema every function of the platform writes, in its order
// (backend/resources/observability/log-schema.json, which a test holds this module to). Written straight to stdout,
// not through console, whose format the runtime of Lambda owns. Internal identifiers only: what could carry a secret
// or personal data (an error of a call, which may name the address it called) is masked first.
import { randomBytes } from 'node:crypto'

export type Level = 'debug' | 'info' | 'notice' | 'warning' | 'error' | 'critical' | 'alert' | 'emergency'

export type DetailValue = string | number | boolean | null | undefined

export interface LogFields {
  readonly event?: string
  readonly outcome?: string
  readonly durationMs?: number
  readonly correlationId?: string | null
  readonly requestId?: string | null
  readonly detail?: Readonly<Record<string, DetailValue>>
  readonly error?: unknown
}

/** The fields of every line, in their order; error and detail come after, when there is one. */
export const LINE_FIELDS = ['time', 'level', 'message', 'function', 'stage', 'correlation_id', 'request_id', 'company_id', 'actor', 'document_id', 'event', 'duration_ms', 'outcome'] as const

const TRACE_FRAMES = 8

const MESSAGE_LIMIT = 500

/** The name of the function without dian-einvoice-<stage>-, as Lambda names it, or the given default. */
export function functionName(fallback: string): string {
  return (process.env.AWS_LAMBDA_FUNCTION_NAME ?? '').replace(/^dian-einvoice-[a-z0-9]+-/, '') || fallback
}

/** Masks what looks like an address, an email or a generated secret, and cuts the text. */
export function redact(text: string, limit: number = MESSAGE_LIMIT): string {
  const masked = text
    .replace(/\b[a-z][a-z0-9+.-]*:\/\/[^\s"'<>]+/gi, '[url]')
    .replace(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g, '[email]')
    .replace(/[A-Za-z0-9+/=_-]{32,}/g, (token) => /\d/.test(token) && /[A-Za-z]/.test(token) && !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(token) ? '[redacted]' : token)
    .replace(/\s+/g, ' ')
    .trim()

  return masked.length > limit ? `${masked.slice(0, limit - 1)}…` : masked
}

/** The error of a line: its class, its masked message, and its first frames without arguments. */
export function errorOf(error: unknown): { class: string, message: string, trace: string[] } {
  if (error instanceof Error) {
    const frames = (error.stack ?? '').split('\n').slice(1).map((frame) => frame.trim().replace(/^at\s+/, '')).filter((frame) => frame !== '')

    return { class: error.name, message: redact(error.message), trace: frames.slice(0, TRACE_FRAMES).map((frame) => redact(frame, 200)) }
  }

  return { class: typeof error, message: redact(String(error)), trace: [] }
}

/** The line itself, as an object in the order of the schema. */
export function line(fn: string, level: Level, message: string, fields: LogFields = {}, now: Date = new Date()): Record<string, unknown> {
  const detail = Object.fromEntries(Object.entries(fields.detail ?? {}).filter((entry): entry is [string, string | number | boolean] => entry[1] !== null && entry[1] !== undefined).map(([key, value]) => [key, typeof value === 'string' ? redact(value, 200) : value]))

  return {
    time: now.toISOString(),
    level,
    message: redact(message),
    function: functionName(fn),
    stage: process.env.STAGE ?? 'local',
    correlation_id: fields.correlationId ?? null,
    request_id: fields.requestId ?? null,
    company_id: null,
    actor: 'system',
    document_id: null,
    event: fields.event ?? null,
    duration_ms: fields.durationMs === undefined ? null : Math.round(fields.durationMs * 10) / 10,
    outcome: fields.outcome ?? null,
    ...(fields.error === undefined ? {} : { error: errorOf(fields.error) }),
    ...(Object.keys(detail).length === 0 ? {} : { detail }),
  }
}

/** A logger of one function: one JSON line per call, to stdout. */
export function logger(fn: string, write: (text: string) => void = (text) => { process.stdout.write(text) }): (level: Level, message: string, fields?: LogFields) => void {
  return (level, message, fields = {}) => {
    write(`${JSON.stringify(line(fn, level, message, fields))}\n`)
  }
}

const ULID_ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz'

/** A new correlation id: a ULID in lower case (48 bits of milliseconds, 80 random), the form the schema asks. */
export function correlationId(now: number = Date.now()): string {
  let time = ''
  let rest = now

  for (let index = 0; index < 10; index++) {
    time = `${ULID_ALPHABET[rest % 32]}${time}`
    rest = Math.floor(rest / 32)
  }

  const random = randomBytes(16)

  return time + Array.from(random, (byte) => ULID_ALPHABET[byte % 32]).join('')
}
