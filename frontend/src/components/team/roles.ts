import type { Role } from '@/api/types'

/** Every role, from the most to the least powerful. */
export const ROLES: Role[] = ['owner', 'admin', 'member', 'viewer']

/** The roles a user may give: only an owner grants the owner role. */
export function assignableRoles(myRole: Role | undefined): Role[] {
  return myRole === 'owner' ? ROLES : ROLES.filter((role) => role !== 'owner')
}
