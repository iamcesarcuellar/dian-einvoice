import { request } from './client'
import type { DataEnvelope, Municipality, Paginated, Reference } from './types'

export const referenceApi = {
  /** DIAN code lists and DANE departments, in one response. */
  async get(): Promise<Reference> {
    return (await request<DataEnvelope<Reference>>('GET', '/reference')).data
  },

  municipalities(params: { department?: string; search?: string; per_page?: number }): Promise<Paginated<Municipality>> {
    return request<Paginated<Municipality>>('GET', '/reference/municipalities', undefined, params)
  },
}
