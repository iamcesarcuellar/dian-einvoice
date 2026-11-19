import type { Component } from 'vue'
import type { ElectronicStatus } from '@/api/types'
import {
  CircleCheckIcon,
  CircleXIcon,
  LoaderCircleIcon,
  MailCheckIcon,
  MailIcon,
  MailMinusIcon,
  MailWarningIcon,
  ShieldAlertIcon,
  ShieldCheckIcon,
  TriangleAlertIcon,
} from '@lucide/vue'

/**
 * The one map of every status label of the app: documents, the electronic document, the delivery,
 * payments, balances, API keys, webhook subscriptions, deliveries and signatures, certificates,
 * numbering, records and invitations. Each state has a single tone (a color of the theme) and a single text
 * (i18n key status.<domain>.<state>), so the same state looks and reads the same everywhere.
 */
export type StatusTone = 'neutral' | 'outline' | 'info' | 'success' | 'warning' | 'danger'

interface StatusStyle {
  tone: StatusTone
  icon?: Component
  /** The icon turns, for states still in progress. */
  spin?: boolean
}

const STATUS = {
  document: {
    draft: { tone: 'outline' },
    issued: { tone: 'neutral' },
  },
  // Pending, generated, signed and sending are shown together as processing (electronicGroup).
  electronic: {
    processing: { tone: 'info', icon: LoaderCircleIcon, spin: true },
    accepted: { tone: 'success', icon: CircleCheckIcon },
    rejected: { tone: 'danger', icon: CircleXIcon },
    failed: { tone: 'warning', icon: TriangleAlertIcon },
  },
  delivery: {
    waiting: { tone: 'outline', icon: MailIcon },
    pending: { tone: 'info', icon: LoaderCircleIcon, spin: true },
    delivered: { tone: 'success', icon: MailCheckIcon },
    failed: { tone: 'warning', icon: MailWarningIcon },
    not_applicable: { tone: 'neutral', icon: MailMinusIcon },
  },
  invoicePayment: {
    unpaid: { tone: 'outline' },
    partially_paid: { tone: 'info' },
    paid: { tone: 'success' },
    overdue: { tone: 'danger' },
  },
  balance: {
    open: { tone: 'outline' },
    partially_credited: { tone: 'info' },
    credited: { tone: 'neutral' },
    voided: { tone: 'danger' },
  },
  payment: {
    recorded: { tone: 'success' },
    voided: { tone: 'danger' },
  },
  // A payment as an invoice sees it: applied, or released by a void.
  allocation: {
    applied: { tone: 'success' },
    released: { tone: 'danger' },
  },
  apiKey: {
    active: { tone: 'success' },
    expired: { tone: 'danger' },
    revoked: { tone: 'danger' },
  },
  subscription: {
    active: { tone: 'success' },
    inactive: { tone: 'outline' },
    disabled: { tone: 'danger' },
  },
  webhookDelivery: {
    pending: { tone: 'info', icon: LoaderCircleIcon, spin: true },
    succeeded: { tone: 'success' },
    failed: { tone: 'warning' },
  },
  certificate: {
    valid: { tone: 'success', icon: ShieldCheckIcon },
    expiring_soon: { tone: 'warning', icon: ShieldAlertIcon },
    expired: { tone: 'danger', icon: ShieldAlertIcon },
    not_yet_valid: { tone: 'info', icon: ShieldAlertIcon },
  },
  numbering: {
    active: { tone: 'success' },
    upcoming: { tone: 'info' },
    expired: { tone: 'danger' },
    exhausted: { tone: 'danger' },
  },
  // Contacts, items, taxes, withholdings and numbering ranges switched on or off.
  record: {
    active: { tone: 'success' },
    inactive: { tone: 'outline' },
  },
  invitation: {
    pending: { tone: 'info' },
    expired: { tone: 'danger' },
    accepted: { tone: 'success' },
    canceled: { tone: 'outline' },
  },
  // A local development service (the mailbox) as the API sees it.
  service: {
    reachable: { tone: 'success' },
    unreachable: { tone: 'danger' },
  },
  // The signature of a webhook, as the local receiver checked it.
  signature: {
    valid: { tone: 'success' },
    invalid_signature: { tone: 'danger' },
    no_secret: { tone: 'warning' },
    missing_headers: { tone: 'danger' },
    timestamp_out_of_tolerance: { tone: 'danger' },
  },
} as const satisfies Record<string, Record<string, StatusStyle>>

export type StatusDomain = keyof typeof STATUS

/** Soft tint of the tone with the tone as text; outline keeps only a border. */
export const TONE_BADGE: Record<StatusTone, string> = {
  neutral: 'border-transparent bg-secondary text-secondary-foreground',
  outline: 'border-border text-muted-foreground',
  info: 'border-transparent bg-info/12 text-info',
  success: 'border-transparent bg-success/12 text-success',
  warning: 'border-transparent bg-warning/14 text-warning',
  danger: 'border-transparent bg-destructive/10 text-destructive',
}

/** The tone as a text color alone, for icons and figures. */
export const TONE_TEXT: Record<StatusTone, string> = {
  neutral: 'text-foreground',
  outline: 'text-muted-foreground',
  info: 'text-info',
  success: 'text-success',
  warning: 'text-warning',
  danger: 'text-destructive',
}

const FALLBACK: StatusStyle = { tone: 'neutral' }

/** Tone and icon of a state; an unknown state is neutral. */
export function statusStyle(domain: StatusDomain, status: string): StatusStyle {
  return (STATUS[domain] as Record<string, StatusStyle>)[status] ?? FALLBACK
}

/** i18n key of the label of a state. */
export function statusKey(domain: StatusDomain, status: string): string {
  return `status.${domain}.${status}`
}

/** The electronic status of the API as the label shows it: the four steps of the chain are processing. */
export function electronicGroup(status: ElectronicStatus): 'processing' | 'accepted' | 'rejected' | 'failed' {
  return status === 'accepted' || status === 'rejected' || status === 'failed' ? status : 'processing'
}
