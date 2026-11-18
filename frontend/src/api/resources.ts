import { request, requestBlob, requestText } from './client'
import type {
  Calculation,
  Contact,
  ContactInput,
  DataEnvelope,
  Invoice,
  InvoiceInput,
  Item,
  ItemInput,
  ListParams,
  NumberTemplate,
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

export const invoicesApi = {
  ...resourceApi<Invoice, InvoiceInput>('/invoices'),

  /** Lines, taxes, withholdings and totals of a draft body, calculated by the API without storing it. */
  async preview(input: InvoiceInput): Promise<Calculation> {
    return (await request<DataEnvelope<Calculation>>('POST', '/invoices/preview', input)).data
  },

  /**
   * Assigns the next number and the CUFE and makes the invoice final; its electronic document chain
   * (XML, signature, transmission to the local DIAN simulator) then runs in the background.
   */
  async issue(id: string): Promise<Invoice> {
    return (await request<DataEnvelope<Invoice>>('POST', `/invoices/${id}/issue`)).data
  },

  /** The signed UBL 2.1 XML of an issued invoice. */
  xml(id: string): Promise<string> {
    return requestText(`/invoices/${id}/xml`)
  },

  /** The ApplicationResponse of the last answer of the local DIAN simulator. */
  dianResponse(id: string): Promise<string> {
    return requestText(`/invoices/${id}/dian-response`)
  },

  /** The next transmission attempt now, or a new run from the step that failed. */
  async retry(id: string): Promise<Invoice> {
    return (await request<DataEnvelope<Invoice>>('POST', `/invoices/${id}/retry`)).data
  },

  /** After a rejection: the chain runs again from the generation of the XML. */
  async resend(id: string): Promise<Invoice> {
    return (await request<DataEnvelope<Invoice>>('POST', `/invoices/${id}/resend`)).data
  },

  /** The PDF, the graphic representation of an accepted invoice. */
  pdf(id: string): Promise<Blob> {
    return requestBlob(`/invoices/${id}/pdf`)
  },

  /** The signed AttachedDocument, with the signed invoice and the response of the local simulator. */
  attachedDocument(id: string): Promise<Blob> {
    return requestBlob(`/invoices/${id}/attached-document`)
  },

  /** The zip the email carries: the AttachedDocument and the PDF. */
  package(id: string): Promise<Blob> {
    return requestBlob(`/invoices/${id}/package`)
  },

  /** Send again, to the current recipient or to another address; it lands in the local mailbox. */
  async deliver(id: string, email: string | null): Promise<Invoice> {
    return (await request<DataEnvelope<Invoice>>('POST', `/invoices/${id}/deliver`, email ? { email } : {})).data
  },

  /** After the PDF failed: a new delivery run from the PDF. */
  async regeneratePdf(id: string): Promise<Invoice> {
    return (await request<DataEnvelope<Invoice>>('POST', `/invoices/${id}/pdf/regenerate`)).data
  },
}
