// Everything the simulator remembers, in one JSON file of its own volume: registered taxpayers,
// processed documents, failure modes, the recent requests, and what the local webhook receiver got. Written after every change, to a
// temporary file renamed over the old one, so a restart never finds it half written.
import { existsSync, mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import type { Finding } from './rules.ts'

export interface Range {
  resolution_number: string
  prefix: string
  from: number
  to: number
  valid_from: string | null
  valid_to: string | null
  technical_key: string
}

export interface Taxpayer {
  nit: string
  check_digit: string | null
  legal_name: string | null
  software: { id: string, pin: string } | null
  ranges: Range[]
  registered_at: string
}

export interface DocumentRecord {
  /** The CUFE of an invoice or the CUDE of a note: the key of the record. */
  cufe: string
  /** Missing in records written before notes existed: those are invoices. */
  kind?: 'invoice' | 'credit_note' | 'debit_note'
  /** A note: the CUFE of the invoice it corrects. */
  references?: string | null
  nit: string
  number: string
  issue_date: string | null
  issuer_name: string | null
  issuer_check_digit: string | null
  status: 'accepted' | 'rejected'
  findings: Finding[]
  response_number: number
  responded_at: string
  xml_file_name: string | null
  received_at: string
  /** SendBillSync requests for it; more than one means it was sent again. */
  submissions: number
}

export type ModeName = 'normal' | 'reject' | 'no_response' | 'error_500' | 'slow' | 'intermittent'

export interface Mode {
  mode: ModeName
  rule: string | null
  delay_ms: number | null
  failure_rate: number | null
  /** Requests it still applies to; null until it is changed. */
  remaining: number | null
  updated_at: string
}

export interface RequestLog {
  at: string
  operation: string
  nit: string | null
  document: string | null
  result: string
  status_code: string | null
  duration_ms: number
  mode: string | null
  rules: string[]
}

export type ReceiverModeName = 'normal' | 'error_500' | 'slow'

export interface ReceiverMode {
  mode: ReceiverModeName
  /** Deliveries it still applies to; null until it is changed. */
  remaining: number | null
  delay_ms: number | null
  updated_at: string
}

export interface ReceivedWebhook {
  received_at: string
  webhook_id: string | null
  timestamp: string | null
  event_id: string | null
  event_type: string | null
  /** data.object.id of the body: the document or payment the event is about. */
  object_id: string | null
  verified: boolean
  /** valid, invalid_signature, no_secret, missing_headers or timestamp_out_of_tolerance. */
  verification: string
  mode: ReceiverModeName
  /** What the receiver answered; slow answers 200 after delay_ms, when the sender may be gone. */
  response_status: number
  delay_ms: number | null
  size: number
  body_preview: string
}

/** The local webhook receiver of one company: secrets by subscription, mode and last webhooks. */
export interface ReceiverCompany {
  secrets: Record<string, string>
  mode: ReceiverMode
  received: ReceivedWebhook[]
}

export interface State {
  version: 1
  created_at: string
  response_sequence: number
  taxpayers: Record<string, Taxpayer>
  documents: Record<string, DocumentRecord>
  modes: Record<string, Mode>
  requests: RequestLog[]
  /** By company id. Missing in state files written before the receiver existed. */
  receiver?: Record<string, ReceiverCompany>
}

export function emptyState(): State {
  return {
    version: 1,
    created_at: new Date().toISOString(),
    response_sequence: 0,
    taxpayers: {},
    documents: {},
    modes: {},
    requests: [],
    receiver: {},
  }
}

export class Store {
  readonly state: State
  private readonly file: string

  constructor(directory: string) {
    mkdirSync(directory, { recursive: true })
    this.file = join(directory, 'state.json')
    this.state = this.load()
  }

  save(): void {
    const temporary = `${this.file}.tmp`
    writeFileSync(temporary, JSON.stringify(this.state))
    renameSync(temporary, this.file)
  }

  reset(): void {
    Object.assign(this.state, emptyState())
    this.save()
  }

  private load(): State {
    if (!existsSync(this.file)) {
      return emptyState()
    }

    try {
      const loaded = JSON.parse(readFileSync(this.file, 'utf8')) as State

      return loaded.version === 1 ? { ...emptyState(), ...loaded } : emptyState()
    } catch (error) {
      console.error(`The state file could not be read, starting empty: ${String(error)}`)
      renameSync(this.file, `${this.file}.unreadable-${Date.now()}`)

      return emptyState()
    }
  }
}
