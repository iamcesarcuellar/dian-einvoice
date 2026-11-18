import { request } from './client'
import type { DataEnvelope, DianSettings, DianSettingsInput, SigningCertificate } from './types'

/** DIAN settings of the current company (X-Company-Id). Owners and admins only. */
export const dianSettingsApi = {
  async get(): Promise<DianSettings> {
    return (await request<DataEnvelope<DianSettings>>('GET', '/dian-settings')).data
  },

  async update(input: DianSettingsInput): Promise<DianSettings> {
    return (await request<DataEnvelope<DianSettings>>('PATCH', '/dian-settings', input)).data
  },
}

/** The signing certificate of the current company. Owners and admins only. */
export const signingCertificateApi = {
  /** Null when the company has none. */
  async get(): Promise<SigningCertificate | null> {
    return (await request<DataEnvelope<SigningCertificate | null>>('GET', '/dian-settings/certificate')).data
  },

  /** Uploads a .p12 or .pfx file with its password; it replaces the current certificate. */
  async upload(file: File, password: string): Promise<SigningCertificate> {
    const form = new FormData()

    form.append('file', file)
    form.append('password', password)

    return (await request<DataEnvelope<SigningCertificate>>('POST', '/dian-settings/certificate', form)).data
  },

  /** Generates a synthetic self-signed test certificate; it replaces the current certificate. */
  async generateTest(): Promise<SigningCertificate> {
    return (await request<DataEnvelope<SigningCertificate>>('POST', '/dian-settings/certificate/test')).data
  },

  remove(): Promise<void> {
    return request<void>('DELETE', '/dian-settings/certificate')
  },
}
