import { idempotent, request, requestBlob, requestText } from './client'
import type {
  Calculation,
  Contact,
  ContactInput,
  DataEnvelope,
  DocumentType,
  Invoice,
  InvoiceInput,
  Item,
  ItemInput,
  ListParams,
  NotePrefill,
  NumberTemplate,
  OpenInvoices,
  Payment,
  PaymentInput,
  Receivables,
  NumberTemplateInput,
  Paginated,
  RateInput,
  Tax,
  Withholding,
} from './types'

/** Typed client of a tenant collection: it acts on the current company (X-Company-Id). */
function resourceApi<T, TInput>(path: string) {
  return {
    list(params: ListParams = {}): Promise<Paginated<T>> {
      return request<Paginated<T>>('GET', path, undefined, params)
    },

    async get(id: string): Promise<T> {
      return (await request<DataEnvelope<T>>('GET', `${path}/${id}`)).data
    },

    async create(input: TInput): Promise<T> {
      return (await request<DataEnvelope<T>>('POST', path, input)).data
    },

    async update(id: string, input: Partial<TInput>): Promise<T> {
      return (await request<DataEnvelope<T>>('PATCH', `${path}/${id}`, input)).data
    },

    remove(id: string): Promise<void> {
      return request<void>('DELETE', `${path}/${id}`)
    },
  }
}

export const contactsApi = resourceApi<Contact, ContactInput>('/contacts')
export const itemsApi = resourceApi<Item, ItemInput>('/items')
export const taxesApi = resourceApi<Tax, RateInput>('/taxes')
export const withholdingsApi = resourceApi<Withholding, RateInput>('/withholdings')
export const numberTemplatesApi = resourceApi<NumberTemplate, NumberTemplateInput>('/number-templates')

/**
 * Client of /invoices, /credit-notes or /debit-notes: the same operations on each type.
 */
function documentApi(path: string) {
  return {
    ...resourceApi<Invoice, InvoiceInput>(path),

    /** Lines, taxes, withholdings and totals of a draft body, calculated by the API without storing it. */
    async preview(input: InvoiceInput): Promise<Calculation> {
      return (await request<DataEnvelope<Calculation>>('POST', `${path}/preview`, input)).data
    },

    /**
     * Assigns the next number and the CUFE and makes the invoice final; its electronic document chain
     * (XML, signature, transmission to the local DIAN simulator) then runs in the background. Each
     * submission carries a new Idempotency-Key.
     */
    async issue(id: string): Promise<Invoice> {
      return (await request<DataEnvelope<Invoice>>('POST', `${path}/${id}/issue`, undefined, undefined, idempotent())).data
    },

    /** The signed UBL 2.1 XML of an issued invoice. */
    xml(id: string): Promise<string> {
      return requestText(`${path}/${id}/xml`)
    },

    /** The ApplicationResponse of the local DIAN simulator's last response. */
    dianResponse(id: string): Promise<string> {
      return requestText(`${path}/${id}/dian-response`)
    },

    /** The next transmission attempt now, or a new run from the step that failed. */
    async retry(id: string): Promise<Invoice> {
      return (await request<DataEnvelope<Invoice>>('POST', `${path}/${id}/retry`)).data
    },

    /** After a rejection: the chain runs again from the generation of the XML. */
    async resend(id: string): Promise<Invoice> {
      return (await request<DataEnvelope<Invoice>>('POST', `${path}/${id}/resend`)).data
    },

    /** The PDF, the graphic representation of an accepted invoice. */
    pdf(id: string): Promise<Blob> {
      return requestBlob(`${path}/${id}/pdf`)
    },

    /** The signed AttachedDocument, with the signed invoice and the response of the local simulator. */
    attachedDocument(id: string): Promise<Blob> {
      return requestBlob(`${path}/${id}/attached-document`)
    },

    /** The zip the email carries: the AttachedDocument and the PDF. */
    package(id: string): Promise<Blob> {
      return requestBlob(`${path}/${id}/package`)
    },

    /** Send again, to the current recipient or to another address; it lands in the local mailbox. */
    async deliver(id: string, email: string | null): Promise<Invoice> {
      return (await request<DataEnvelope<Invoice>>('POST', `${path}/${id}/deliver`, email ? { email } : {})).data
    },

    /** After the PDF failed: a new delivery run from the PDF. */
    async regeneratePdf(id: string): Promise<Invoice> {
      return (await request<DataEnvelope<Invoice>>('POST', `${path}/${id}/pdf/regenerate`)).data
    },
  }
}

export const invoicesApi = documentApi('/invoices')

/** A credit or debit note not saved yet, for an accepted invoice: its lines, terms and balance. */
function prefill(path: string) {
  return async (invoiceId: string, voidInvoice = false): Promise<NotePrefill> =>
    (
      await request<DataEnvelope<NotePrefill>>('GET', `${path}/prefill`, undefined, {
        invoice_id: invoiceId,
        void: voidInvoice ? 1 : undefined,
      })
    ).data
}

export const creditNotesApi = { ...documentApi('/credit-notes'), prefill: prefill('/credit-notes') }

export const debitNotesApi = { ...documentApi('/debit-notes'), prefill: prefill('/debit-notes') }

/** The client of a document type. */
export function documentsApi(type: DocumentType) {
  return type === 'credit_note' ? creditNotesApi : type === 'debit_note' ? debitNotesApi : invoicesApi
}

/** Payments received from customers: recorded and applied to their invoices, or voided with a reason. */
export const paymentsApi = {
  list(params: ListParams = {}): Promise<Paginated<Payment>> {
    return request<Paginated<Payment>>('GET', '/payments', undefined, params)
  },

  async get(id: string): Promise<Payment> {
    return (await request<DataEnvelope<Payment>>('GET', `/payments/${id}`)).data
  },

  /** Each submission carries a new Idempotency-Key, so a repeated request never records it twice. */
  async create(input: PaymentInput): Promise<Payment> {
    return (await request<DataEnvelope<Payment>>('POST', '/payments', input, undefined, idempotent())).data
  },

  async void(id: string, reason: string): Promise<Payment> {
    return (await request<DataEnvelope<Payment>>('POST', `/payments/${id}/void`, { reason }, undefined, idempotent())).data
  },

  /** The accepted invoices of a customer with something left to collect, oldest due first. */
  openInvoices(contactId: string): Promise<OpenInvoices> {
    return request<OpenInvoices>('GET', `/contacts/${contactId}/open-invoices`)
  },

  /** Accounts receivable by customer, with the aging buckets and the totals. */
  async receivables(): Promise<Receivables> {
    return (await request<DataEnvelope<Receivables>>('GET', '/receivables')).data
  },
}
