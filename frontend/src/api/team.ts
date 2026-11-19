import type { DataEnvelope, Me, Role } from './types'
import { request } from './client'

/** A member of the current company. */
export interface TeamMember {
  /** The membership. */
  id: string
  user_id: string
  name: string
  email: string
  role: Role
  joined_at: string
  is_current_user: boolean
}

export type InvitationStatus = 'pending' | 'expired' | 'canceled' | 'accepted'

/** An invitation of the current company that was neither accepted nor canceled. */
export interface TeamInvitation {
  id: string
  email: string
  role: Role
  status: 'pending' | 'expired'
  invited_by: { id: string; name: string } | null
  expires_at: string
  last_sent_at: string | null
  created_at: string
}

/** An invitation as its link shows it, before signing in. */
export interface InvitationPreview {
  email: string
  role: Role
  status: InvitationStatus
  expires_at: string
  company: { id: string; name: string }
  invited_by: string | null
  /** The email already has an account: it joins by signing in as that user. */
  existing_user: boolean
}

interface SentInvitation {
  data: TeamInvitation
  meta: { email_queued: boolean }
}

/** Members and invitations of the current company; owners and admins only. */
export const teamApi = {
  async members(): Promise<TeamMember[]> {
    return (await request<DataEnvelope<TeamMember[]>>('GET', '/team/members')).data
  },

  async changeRole(memberId: string, role: Role): Promise<TeamMember> {
    return (await request<DataEnvelope<TeamMember>>('PATCH', `/team/members/${memberId}`, { role })).data
  },

  removeMember(memberId: string): Promise<void> {
    return request<void>('DELETE', `/team/members/${memberId}`)
  },

  async invitations(): Promise<TeamInvitation[]> {
    return (await request<DataEnvelope<TeamInvitation[]>>('GET', '/team/invitations')).data
  },

  /** The email goes to the queue, which retries it; email_queued tells whether it was queued. */
  invite(email: string, role: Role): Promise<SentInvitation> {
    return request<SentInvitation>('POST', '/team/invitations', { email, role })
  },

  /** A new link, valid for 7 more days; the old one stops working. */
  resend(invitationId: string): Promise<SentInvitation> {
    return request<SentInvitation>('POST', `/team/invitations/${invitationId}/resend`)
  },

  cancel(invitationId: string): Promise<void> {
    return request<void>('DELETE', `/team/invitations/${invitationId}`)
  },
}

/** The link of an invitation email, with or without a session. */
export const invitationApi = {
  async get(token: string): Promise<InvitationPreview> {
    return (await request<DataEnvelope<InvitationPreview>>('GET', `/invitations/${encodeURIComponent(token)}`)).data
  },

  /**
   * Joins the company: as the signed-in user of the invited email, or with a new account (name and
   * password) when the email has none. Answers the user and the company joined.
   */
  async accept(token: string, account?: { name: string; password: string; password_confirmation: string }): Promise<{ me: Me; companyId: string }> {
    const answer = await request<DataEnvelope<Me> & { meta: { company_id: string } }>('POST', `/invitations/${encodeURIComponent(token)}/accept`, account ?? {})

    return { me: answer.data, companyId: answer.meta.company_id }
  },
}
