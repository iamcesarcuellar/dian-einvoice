import { request } from './client'
import type { DataEnvelope, DianSettings, DianSettingsInput } from './types'

/** DIAN settings of the current company (X-Company-Id). Owners and admins only. */
export const dianSettingsApi = {
  async get(): Promise<DianSettings> {
    return (await request<DataEnvelope<DianSettings>>('GET', '/dian-settings')).data
  },

  async update(input: DianSettingsInput): Promise<DianSettings> {
    return (await request<DataEnvelope<DianSettings>>('PATCH', '/dian-settings', input)).data
  },
}
