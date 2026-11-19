import type { RouteLocationRaw } from 'vue-router'
import type { DocumentType } from '@/api/types'

/** Route names of each document type: its list tab, its page and its editor for a new one. */
export const DOCUMENT_ROUTES: Record<DocumentType, { list: string; detail: string; create: string }> = {
  invoice: { list: 'invoices', detail: 'invoice', create: 'invoice-new' },
  credit_note: { list: 'credit-notes', detail: 'credit-note', create: 'credit-note-new' },
  debit_note: { list: 'debit-notes', detail: 'debit-note', create: 'debit-note-new' },
}

/** Query resource of each type, as tenantKey and invalidateTenant name it. */
export const DOCUMENT_RESOURCES: Record<DocumentType, string> = {
  invoice: 'invoices',
  credit_note: 'credit-notes',
  debit_note: 'debit-notes',
}

/** i18n key of the type in the documents namespace: invoice, creditNote or debitNote. */
export const DOCUMENT_KEYS: Record<DocumentType, string> = {
  invoice: 'invoice',
  credit_note: 'creditNote',
  debit_note: 'debitNote',
}

/** Code of the void concept of a credit note (table 13.2.4). */
export const VOID_CONCEPT = '2'

/** Every document resource: a change on a note changes the balance of its invoice. */
export const ALL_DOCUMENT_RESOURCES = Object.values(DOCUMENT_RESOURCES)

/**
 * The breadcrumbs of a page of a document: Sales, Invoices, the tab of a note (Credit notes or
 * Debit notes), then the page itself.
 */
export function documentCrumbs(type: DocumentType, current: string, t: (key: string) => string): Array<{ label: string; to?: RouteLocationRaw }> {
  return [
    { label: t('nav.sales') },
    { label: t('nav.invoices'), to: { name: DOCUMENT_ROUTES.invoice.list } },
    ...(type === 'invoice' ? [] : [{ label: t(`notes.tabs.${DOCUMENT_KEYS[type]}s`), to: { name: DOCUMENT_ROUTES[type].list } }]),
    { label: current },
  ]
}
