import type { DataEnvelope, DocumentStatus, DocumentType, ElectronicStatus, PaymentStatus } from './types'
import type { AgingBucket, CertificateStatus, NumberingStatus } from './types'
import { request } from './client'

/** A figure of the month to date, with the same days of the previous month. */
export interface DashboardKpi {
  amount: string
  count: number
  previous_amount: string
  previous_count: number
  /** Percent change against the previous amount, one decimal; null when that amount is zero. */
  change: string | null
}

export interface DashboardMonth {
  /** YYYY-MM */
  month: string
  invoiced: string
  invoices: number
  collected: string
  payments: number
}

export type AttentionProblem = 'rejected' | 'failed' | 'delivery_failed'

export interface AttentionItem {
  id: string
  type: DocumentType
  full_number: string | null
  customer_name: string | null
  issue_date: string | null
  problem: AttentionProblem
  detail: string | null
  since: string | null
}

export interface NumberingNotice {
  id: string
  prefix: string | null
  used: number
  total: number
  /** Percent of the range used, one decimal. */
  usage: string
  valid_to: string | null
  days_left: number | null
  status: NumberingStatus
  warning: boolean
}

export interface CertificateNotice {
  subject: string
  valid_to: string
  days_left: number
  status: CertificateStatus
  is_test: boolean
  warning: boolean
}

export interface ActivityEntry {
  id: string
  source: 'document' | 'payment'
  type: string
  occurred_at: string
  actor: { type: 'user' | 'api_key'; name: string } | null
  subject: { type: DocumentType | 'payment'; id: string; number: string | null; customer_name: string | null }
  amount: string | null
  detail: string | null
}

/** GET /dashboard: everything the dashboard shows, in one answer. */
export interface Dashboard {
  currency: string
  today: string
  has_invoices: boolean
  period: { from: string; to: string; previous_from: string; previous_to: string }
  kpis: Record<'invoiced' | 'collected' | 'receivable' | 'overdue', DashboardKpi>
  monthly: DashboardMonth[]
  aging: Record<AgingBucket | 'total', string>
  electronic: { days: number; total: number; accepted: number; processing: number; rejected: number; failed: number }
  attention: { total: number; items: AttentionItem[] }
  notices: { numbering: NumberingNotice | null; certificate: CertificateNotice | null }
  top_customers: Array<{ contact_id: string; name: string; amount: string; invoices: number }>
  activity: ActivityEntry[]
}

export interface SearchDocument {
  id: string
  type: DocumentType
  status: DocumentStatus
  full_number: string | null
  customer_name: string | null
  issue_date: string | null
  payable_amount: string
  electronic_status: ElectronicStatus | null
  related_number: string | null
}

export interface SearchPayment {
  id: string
  full_number: string
  customer_name: string
  payment_date: string
  amount: string
  status: PaymentStatus
}

export interface SearchContact {
  id: string
  legal_name: string
  trade_name: string | null
  identification_type: string
  identification_number: string
  check_digit: number | null
  email: string | null
  is_active: boolean
}

export interface SearchItem {
  id: string
  code: string
  name: string
  type: 'product' | 'service'
  unit_price: string
  is_active: boolean
}

interface SearchGroup<T> {
  total: number
  items: T[]
}

/** GET /search: a few matches of each kind in the current company. */
export interface SearchResults {
  query: string
  groups: {
    invoices: SearchGroup<SearchDocument>
    notes: SearchGroup<SearchDocument>
    payments: SearchGroup<SearchPayment>
    contacts: SearchGroup<SearchContact>
    items: SearchGroup<SearchItem>
  }
}

/** The dashboard and the global search of the web app (session only). */
export const workspaceApi = {
  async dashboard(): Promise<Dashboard> {
    return (await request<DataEnvelope<Dashboard>>('GET', '/dashboard')).data
  },

  async search(query: string): Promise<SearchResults> {
    return (await request<DataEnvelope<SearchResults>>('GET', '/search', undefined, { q: query })).data
  },
}
