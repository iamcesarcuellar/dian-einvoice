import { request } from './client'
import type { DataEnvelope, LoginInput, Me, RegisterInput } from './types'

export const authApi = {
  /** Creates the user, their first company and their owner membership, and signs them in. */
  async register(input: RegisterInput): Promise<Me> {
    return (await request<DataEnvelope<Me>>('POST', '/auth/register', input)).data
  },

  async login(input: LoginInput): Promise<Me> {
    return (await request<DataEnvelope<Me>>('POST', '/auth/login', input)).data
  },

  logout(): Promise<void> {
    return request<void>('POST', '/auth/logout')
  },

  /** The signed-in user with their companies and the role in each one. */
  async me(): Promise<Me> {
    return (await request<DataEnvelope<Me>>('GET', '/auth/me')).data
  },

  /** Changes the name of the signed-in user. */
  async updateProfile(input: { name: string }): Promise<Me> {
    return (await request<DataEnvelope<Me>>('PATCH', '/auth/me', input)).data
  },

  /** Changes the password; the current one is asked again. */
  changePassword(input: { current_password: string; password: string; password_confirmation: string }): Promise<void> {
    return request<void>('PUT', '/auth/password', input)
  },
}
