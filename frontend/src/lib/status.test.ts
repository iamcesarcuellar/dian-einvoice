import type { DeliveryStatus, ElectronicStatus, InvoicePaymentStatus, PaymentStatus } from '@/api/types'
import type { StatusDomain } from '@/lib/status'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import StatusBadge from '@/components/StatusBadge.vue'
import { i18n } from '@/i18n'
import { electronicGroup, statusKey, statusStyle } from '@/lib/status'

// Every state of the API types, as records: the type check fails when a state is added there and
// not here. Delivery also has waiting (not started yet) and the invoice payment overdue, both
// worked out by the app.
const ELECTRONIC: Record<ElectronicStatus, true> = {
  pending: true,
  generated: true,
  signed: true,
  sending: true,
  accepted: true,
  rejected: true,
  failed: true,
}
const DELIVERY: Record<DeliveryStatus | 'waiting', true> = { waiting: true, pending: true, delivered: true, failed: true, not_applicable: true }
const INVOICE_PAYMENT: Record<InvoicePaymentStatus | 'overdue', true> = { unpaid: true, partially_paid: true, paid: true, overdue: true }
const PAYMENT: Record<PaymentStatus, true> = { recorded: true, voided: true }

/** Each state: its domain, the state, and the state its label shows. */
const STATES: Array<[StatusDomain, string, string]> = [
  ...Object.keys(ELECTRONIC).map((status): [StatusDomain, string, string] => ['electronic', status, electronicGroup(status as ElectronicStatus)]),
  ...Object.keys(DELIVERY).map((status): [StatusDomain, string, string] => ['delivery', status, status]),
  ...Object.keys(INVOICE_PAYMENT).map((status): [StatusDomain, string, string] => ['invoicePayment', status, status]),
  ...Object.keys(PAYMENT).map((status): [StatusDomain, string, string] => ['payment', status, status]),
]

function label(domain: StatusDomain, status: string): string | null {
  const key = statusKey(domain, status)

  return i18n.global.te(key) ? i18n.global.t(key) : null
}

describe('status labels', () => {
  it.each(STATES)('%s %s shows as %s, with its own tone and a label', (domain, _status, shown) => {
    // An unknown state gets the shared neutral fallback; a known one never does.
    expect(statusStyle(domain, shown)).not.toBe(statusStyle(domain, 'no-such-state'))
    expect(label(domain, shown)).toMatch(/\S/)
  })

  it('shows the four steps before the answer of the DIAN as processing', () => {
    expect(['pending', 'generated', 'signed', 'sending'].map((status) => electronicGroup(status as ElectronicStatus))).toEqual([
      'processing',
      'processing',
      'processing',
      'processing',
    ])
  })

  it('gives the outcomes their meaning: accepted, delivered and paid are success; rejected and overdue are danger', () => {
    expect(statusStyle('electronic', 'accepted').tone).toBe('success')
    expect(statusStyle('delivery', 'delivered').tone).toBe('success')
    expect(statusStyle('invoicePayment', 'paid').tone).toBe('success')
    expect(statusStyle('electronic', 'rejected').tone).toBe('danger')
    expect(statusStyle('invoicePayment', 'overdue').tone).toBe('danger')
  })

  it('shows a state it does not know as the API sends it, in the neutral tone', () => {
    expect(statusStyle('delivery', 'bounced')).toEqual({ tone: 'neutral' })

    const badge = mount(StatusBadge, { props: { domain: 'delivery', status: 'bounced' }, global: { plugins: [i18n] } })

    expect(badge.text()).toBe('bounced')
  })

  it('shows a known state with its label', () => {
    const badge = mount(StatusBadge, { props: { domain: 'electronic', status: 'accepted' }, global: { plugins: [i18n] } })

    expect(badge.text()).toBe('Accepted')
  })
})
