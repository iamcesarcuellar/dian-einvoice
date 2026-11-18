import { request } from './client'
import type { DataEnvelope, LocalMailboxStatus } from './types'

/** The local development mailbox, a development tool that catches every email. Owners and admins. */
export const localMailboxApi = {
  async get(): Promise<LocalMailboxStatus> {
    return (await request<DataEnvelope<LocalMailboxStatus>>('GET', '/local-mailbox')).data
  },
}
