import { request, requestText } from './client'
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

  /** Assigns the next number and the CUFE, makes the invoice final and generates its XML. */
  async issue(id: string): Promise<Invoice> {
    return (await request<DataEnvelope<Invoice>>('POST', `/invoices/${id}/issue`)).data
  },

  /** The unsigned UBL 2.1 XML of an issued invoice. */
  xml(id: string): Promise<string> {
    return requestText(`/invoices/${id}/xml`)
  },

  /** Generates the XML again, such as after a failure. */
  async regenerateXml(id: string): Promise<Invoice> {
    return (await request<DataEnvelope<Invoice>>('POST', `/invoices/${id}/xml/regenerate`)).data
  },
}
