import type { Permission } from '@/api/types'
import { useQuery } from '@tanstack/vue-query'
import { computed } from 'vue'
import { meQuery } from '@/lib/query'
import { useSessionStore } from '@/stores/session'

/** The signed-in user, their companies and the company they are working in. */
export function useSession() {
  const store = useSessionStore()
  const { data } = useQuery(meQuery)

  const user = computed(() => data.value ?? null)
  const companies = computed(() => user.value?.companies ?? [])
  const currentCompany = computed(
    () => companies.value.find((company) => company.id === store.currentCompanyId) ?? null,
  )

  /** Whether the user's role in the current company allows the permission; reading is always allowed. */
  function can(permission: Permission): boolean {
    return currentCompany.value?.permissions?.includes(permission) ?? false
  }

  return { user, companies, currentCompany, can }
}
