import { request } from './client'
import type {
  Contact,
  ContactInput,
  DataEnvelope,
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
