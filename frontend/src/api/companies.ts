import { request } from './client'
import type { Company, CompanyInput, DataEnvelope } from './types'

export const companiesApi = {
  /** Creates a company owned by the signed-in user. */
  async create(input: CompanyInput): Promise<Company> {
    return (await request<DataEnvelope<Company>>('POST', '/companies', input)).data
  },

  async get(id: string): Promise<Company> {
    return (await request<DataEnvelope<Company>>('GET', `/companies/${id}`)).data
  },

  /** Owners and admins only. */
  async update(id: string, input: Partial<CompanyInput>): Promise<Company> {
    return (await request<DataEnvelope<Company>>('PATCH', `/companies/${id}`, input)).data
  },
}
